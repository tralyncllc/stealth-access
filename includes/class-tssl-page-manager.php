<?php
/**
 * Auto-creates the page that hosts the login shortcode and (optionally)
 * hides it from public navigation / page-list outputs.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

class TSSL_Page_Manager {

	/**
	 * Option flag that requests a one-time rewrite-rules flush on the next
	 * page load. Set whenever the login page is created, adopted, or
	 * re-slugged; consumed (and deleted) by maybe_flush_rewrite_rules() on
	 * `wp_loaded`. This keeps flushes off the hot path — we never flush on
	 * a normal request, only once after the login page actually changes.
	 */
	const FLUSH_FLAG = 'tssl_flush_rewrite_rules';

	private TSSL_Settings $settings;
	private bool $renaming = false;
	private bool $reconciling = false;

	public function __construct( TSSL_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		// Reconcile the tracked login page against the configured slug
		// whenever settings are saved. Replaces the older split
		// rename/create-on-save handlers with one self-healing routine that
		// can also repair stale / wrong / missing IDs.
		add_action( 'update_option_' . TSSL_Settings::OPTION_KEY, array( $this, 'reconcile_on_save' ), 10, 0 );

		// Self-heal on every admin page load (cheap + idempotent): a fresh
		// install, a slug change that didn't take, or a stale/wrong stored
		// login_page_id all get repaired the moment an admin opens wp-admin.
		add_action( 'admin_init', array( $this, 'reconcile_login_page' ) );

		// Execute a scheduled one-time rewrite flush (set by the reconcile /
		// create paths). Cheap option check on each load; only flushes when
		// the login page actually changed.
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );

		add_filter( 'wp_list_pages_excludes', array( $this, 'filter_wp_list_pages_excludes' ) );
		add_filter( 'get_pages', array( $this, 'filter_get_pages' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_objects' ) );
		add_filter( 'render_block_core/navigation-link', array( $this, 'filter_navigation_link' ), 10, 2 );

		// REST API: anonymous callers must not be able to discover the login
		// page via /wp/v2/pages or /wp/v2/search. Authenticated editors keep
		// full visibility so they can manage the page from the block editor.
		// (Audit finding M5.)
		add_filter( 'rest_page_query', array( $this, 'filter_rest_page_query' ), 10, 2 );
		add_filter( 'rest_post_search_query', array( $this, 'filter_rest_post_search_query' ), 10, 2 );

		// WordPress core XML sitemaps (wp-sitemap.xml, /wp-sitemap-posts-page-1.xml).
		// Exclude the login page from the published sitemap when
		// hide_login_page_from_lists is on. (Audit finding M6.)
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_sitemap_query_args' ), 10, 2 );

		// Front-end search results. Exclude the login page from /?s=<term>
		// queries when the caller cannot edit pages. (Audit finding M7.)
		add_action( 'pre_get_posts', array( $this, 'filter_search_query' ) );

		// Theme isolation: route the login page through a plugin-owned
		// template that omits the active theme's header/footer/sidebar.
		add_filter( 'template_include', array( $this, 'maybe_override_template' ), 99 );

		// Pre-enqueue login.css in `wp_enqueue_scripts` so it lands in <head>
		// (the shortcode render runs during `the_content()`, which is after
		// `wp_head()` has already been printed).
		//
		// Priority 999 (vs the WordPress default 10) so our handle is added
		// to `WP_Styles::$queue` AFTER the active theme's `style.css` — the
		// resulting <link> tag is printed last in <head>, which means our
		// rules win source-order ties on the cascade. See
		// `Login_Portal_CSS_Hardening_Report.md` for the override audit
		// this guards against.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_preload_login_assets' ), 999 );

		// Make sure `body_class` carries the portal class even when the
		// template-include runs via WordPress's normal singular flow.
		add_filter( 'body_class', array( $this, 'maybe_add_portal_body_class' ) );
	}

	public function maybe_create_login_page(): void {
		$opts = $this->settings->get_all();

		if ( empty( $opts['auto_create_login_page'] ) ) {
			return;
		}

		if ( ! empty( $opts['login_page_id'] ) ) {
			$existing = get_post( (int) $opts['login_page_id'] );
			if ( $existing instanceof WP_Post && 'trash' !== $existing->post_status ) {
				return;
			}
		}

		$slug = sanitize_title( $opts['custom_login_slug'] ?: 'secure-login' );

		$existing = get_page_by_path( $slug );
		if ( $existing instanceof WP_Post ) {
			$this->settings->update( 'login_page_id', (int) $existing->ID );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'     => __( 'Secure Login', 'stealth-access' ),
				'post_name'      => $slug,
				'post_content'   => '[' . TSSL_Login_Flow::SHORTCODE . ']',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return;
		}

		$this->settings->update( 'login_page_id', (int) $page_id );
		$this->schedule_rewrite_flush();
	}

	/**
	 * `update_option_<key>` callback. Defers to the self-healing reconcile so
	 * a slug change on save always lands on the tracked page (and repairs the
	 * id if it was stale). Re-entrancy is guarded inside reconcile_login_page.
	 */
	public function reconcile_on_save(): void {
		$this->reconcile_login_page();
	}

	/**
	 * Ensure the tracked login page exists, is published, and actually sits
	 * at the configured custom slug — repairing whatever has drifted.
	 *
	 * This is the core self-heal for the fresh-install / divergence class of
	 * bug where `custom_login_slug` in settings pointed at one slug while the
	 * tracked page kept another `post_name`, so the configured URL 404'd even
	 * though a perfectly good published login page existed. The routine is
	 * idempotent: once state is consistent it performs no writes, so it is
	 * safe to run on every admin load.
	 *
	 * Repairs, in order of preference:
	 *   1. Tracked page valid  → publish it if needed, re-slug it to the
	 *      configured slug if its post_name drifted.
	 *   2. Tracked id stale/0/wrong → adopt a page already sitting at the
	 *      configured slug, else any page carrying our shortcode, then
	 *      re-slug + re-track it.
	 *   3. Nothing to adopt → create a fresh login page.
	 *
	 * Any create / adopt / re-slug schedules a one-time rewrite flush so the
	 * new URL resolves even on hosts that cache rewrite rules aggressively.
	 */
	public function reconcile_login_page(): void {
		if ( $this->reconciling ) {
			return;
		}

		$opts = $this->settings->get_all();
		if ( empty( $opts['auto_create_login_page'] ) ) {
			// Admin opted out of plugin-managed login pages — leave it alone.
			return;
		}

		$slug = sanitize_title( (string) ( $opts['custom_login_slug'] ?: 'secure-login' ) );
		if ( '' === $slug ) {
			return;
		}

		$this->reconciling = true;

		$tracked = (int) ( $opts['login_page_id'] ?? 0 );
		$page    = $tracked > 0 ? get_post( $tracked ) : null;
		$valid   = $page instanceof WP_Post
			&& 'page' === $page->post_type
			&& 'trash' !== $page->post_status;

		if ( ! $valid ) {
			// Stored id is 0 / missing / trashed / not a page. Try to adopt an
			// existing login page before creating a brand-new one.
			$page = $this->find_adoptable_login_page( $slug );
			if ( $page instanceof WP_Post ) {
				if ( (int) $page->ID !== $tracked ) {
					$this->settings->update( 'login_page_id', (int) $page->ID );
				}
			} else {
				// Nothing to adopt → create (stores id + schedules flush).
				$this->maybe_create_login_page();
				$this->reconciling = false;
				return;
			}
		}

		$changed = false;

		// Make sure the page is published so the URL resolves at all.
		if ( 'publish' !== $page->post_status ) {
			wp_update_post(
				array(
					'ID'          => (int) $page->ID,
					'post_status' => 'publish',
				)
			);
			$changed = true;
		}

		// Core self-heal: the page's slug must equal the configured slug, or
		// the configured URL 404s.
		if ( $page->post_name !== $slug ) {
			$shortcode = '[' . TSSL_Login_Flow::SHORTCODE . ']';

			// Prefer adopting a DIFFERENT page that already owns the configured
			// slug and carries our shortcode, rather than fighting `wp_unique_
			// post_slug` (which would just suffix this page to `slug-2`). This
			// is the duplicate case: login_page_id points at `slug-2` while a
			// real login page already sits at `slug`. Switch tracking to the
			// configured-slug page; never delete the now-untracked duplicate.
			$owner = get_page_by_path( $slug );
			if ( $owner instanceof WP_Post
				&& (int) $owner->ID !== (int) $page->ID
				&& 'trash' !== $owner->post_status
				&& false !== strpos( (string) $owner->post_content, $shortcode )
			) {
				if ( 'publish' !== $owner->post_status ) {
					wp_update_post(
						array(
							'ID'          => (int) $owner->ID,
							'post_status' => 'publish',
						)
					);
				}
				$this->settings->update( 'login_page_id', (int) $owner->ID );
				$changed = true;
			} else {
				// No conflicting login page at the slug — re-slug this page.
				$applied = $this->set_login_page_slug( (int) $page->ID, $slug );
				if ( $applied !== $slug ) {
					// Slug taken by some other post. Adopt whatever truly sits
					// there only if it is itself a login page; otherwise leave
					// the suffixed slug rather than hijack an unrelated page.
					$owner2 = get_page_by_path( $slug );
					if ( $owner2 instanceof WP_Post
						&& (int) $owner2->ID !== (int) $page->ID
						&& false !== strpos( (string) $owner2->post_content, $shortcode )
					) {
						$this->settings->update( 'login_page_id', (int) $owner2->ID );
					}
				}
				$changed = true;
			}
		}

		if ( $changed ) {
			$this->schedule_rewrite_flush();
		}

		$this->reconciling = false;
	}

	/**
	 * Find a login page to adopt when the tracked id is unusable. Prefers a
	 * page already sitting at the configured slug; falls back to any page
	 * carrying our login shortcode (slug drifted elsewhere).
	 *
	 * @param string $slug The configured login slug.
	 * @return WP_Post|null
	 */
	private function find_adoptable_login_page( string $slug ): ?WP_Post {
		$shortcode = '[' . TSSL_Login_Flow::SHORTCODE . ']';

		// 1. A page already living at the configured slug that carries our
		//    shortcode (don't hijack an unrelated page that happens to share
		//    the slug).
		$at_path = get_page_by_path( $slug );
		if ( $at_path instanceof WP_Post
			&& 'trash' !== $at_path->post_status
			&& false !== strpos( (string) $at_path->post_content, $shortcode )
		) {
			return $at_path;
		}

		// 2. Any non-trashed page carrying our shortcode (the slug drifted to
		//    something else). Pick the lowest ID for determinism.
		$candidates = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				's'                => $shortcode,
				'suppress_filters' => true,
			)
		);
		if ( ! empty( $candidates ) && $candidates[0] instanceof WP_Post ) {
			return $candidates[0];
		}

		return null;
	}

	/**
	 * Update a page's post_name and return the slug WordPress actually
	 * applied (which may be suffixed if the slug was taken). Reuses the
	 * $renaming guard so any legacy rename hooks don't recurse.
	 *
	 * @param int    $page_id Target page.
	 * @param string $slug    Desired slug.
	 * @return string The slug actually stored.
	 */
	private function set_login_page_slug( int $page_id, string $slug ): string {
		$this->renaming = true;
		wp_update_post(
			array(
				'ID'        => $page_id,
				'post_name' => $slug,
			)
		);
		$this->renaming = false;

		$fresh = get_post( $page_id );
		return $fresh instanceof WP_Post ? (string) $fresh->post_name : '';
	}

	/**
	 * Request a one-time rewrite-rules flush on the next page load. Cheap,
	 * non-autoloaded option; consumed by maybe_flush_rewrite_rules().
	 */
	private function schedule_rewrite_flush(): void {
		update_option( self::FLUSH_FLAG, 1, false );
	}

	/**
	 * `wp_loaded` callback. Performs a single soft rewrite flush when one was
	 * scheduled, then clears the flag so we never flush on a normal request.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( ! get_option( self::FLUSH_FLAG ) ) {
			return;
		}
		delete_option( self::FLUSH_FLAG );
		// Soft flush: pages resolve via the generic `pagename` rule, so we
		// never need to regenerate the .htaccess / web.config block.
		flush_rewrite_rules( false );
	}

	private function should_hide_from_lists(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( ! $this->settings->get( 'hide_login_page_from_lists' ) ) {
			return false;
		}
		if ( $this->login_page_id() <= 0 ) {
			return false;
		}
		return true;
	}

	private function login_page_id(): int {
		return (int) $this->settings->get( 'login_page_id' );
	}

	public function filter_wp_list_pages_excludes( $exclude_array ) {
		if ( ! $this->should_hide_from_lists() ) {
			return $exclude_array;
		}
		if ( ! is_array( $exclude_array ) ) {
			$exclude_array = array();
		}
		$exclude_array[] = $this->login_page_id();
		return $exclude_array;
	}

	public function filter_get_pages( $pages, $parsed_args ) {
		if ( ! $this->should_hide_from_lists() ) {
			return $pages;
		}
		if ( ! is_array( $pages ) ) {
			return $pages;
		}
		$login_id = $this->login_page_id();

		if ( ! empty( $parsed_args['include'] ) ) {
			$included = is_array( $parsed_args['include'] )
				? $parsed_args['include']
				: explode( ',', (string) $parsed_args['include'] );
			$included = array_map( 'intval', $included );
			if ( in_array( $login_id, $included, true ) ) {
				return $pages;
			}
		}

		return array_values(
			array_filter(
				$pages,
				static function ( $page ) use ( $login_id ) {
					return isset( $page->ID ) && (int) $page->ID !== $login_id;
				}
			)
		);
	}

	public function filter_nav_menu_objects( $items ) {
		if ( ! $this->should_hide_from_lists() ) {
			return $items;
		}
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$login_id = $this->login_page_id();
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $login_id ) {
					if ( isset( $item->object, $item->object_id )
						&& 'page' === $item->object
						&& (int) $item->object_id === $login_id
					) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * Shared helper for the REST + sitemap + search filters: returns true
	 * when the login page should be hidden from the current caller. Differs
	 * from should_hide_from_lists() in that it INTENTIONALLY runs during
	 * REST and search contexts (those are the contexts we now want to gate)
	 * and only suppresses for unauthenticated callers.
	 */
	private function should_hide_for_anon_caller(): bool {
		if ( ! $this->settings->get( 'hide_login_page_from_lists' ) ) {
			return false;
		}
		if ( $this->login_page_id() <= 0 ) {
			return false;
		}
		if ( current_user_can( 'edit_pages' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Append the login page ID to a `post__not_in` style argument array
	 * while preserving any IDs the caller already requested be excluded.
	 *
	 * @param array<string,mixed> $args     Query args.
	 * @param string              $arg_name Key inside $args to extend.
	 * @return array<string,mixed>
	 */
	private function append_login_id_exclusion( array $args, string $arg_name = 'post__not_in' ): array {
		$existing = array();
		if ( isset( $args[ $arg_name ] ) ) {
			$existing = is_array( $args[ $arg_name ] )
				? $args[ $arg_name ]
				: explode( ',', (string) $args[ $arg_name ] );
		}
		$existing            = array_map( 'intval', $existing );
		$existing[]          = $this->login_page_id();
		$args[ $arg_name ]   = array_values( array_unique( array_filter( $existing ) ) );
		return $args;
	}

	/**
	 * REST `/wp/v2/pages` query filter. Excludes the login page from the
	 * response for anonymous callers, leaves admin/editor responses
	 * unchanged. The audit (M5) confirmed that the prior REST short-circuit
	 * in should_hide_from_lists() leaked the slug + permalink + shortcode
	 * content to anyone via curl.
	 *
	 * @param array<string,mixed> $args    WP_Query args used by the controller.
	 * @param mixed               $request WP_REST_Request (unused here, included
	 *                                     for filter signature parity).
	 * @return array<string,mixed>
	 */
	public function filter_rest_page_query( $args, $request ) {
		unset( $request );
		if ( ! $this->should_hide_for_anon_caller() ) {
			return is_array( $args ) ? $args : array();
		}
		return $this->append_login_id_exclusion( is_array( $args ) ? $args : array() );
	}

	/**
	 * REST `/wp/v2/search` query filter. Same purpose as
	 * filter_rest_page_query but for the cross-type search endpoint, which
	 * also returns page objects matching a keyword. (Audit finding M5.)
	 *
	 * @param array<string,mixed> $args    WP_Query args.
	 * @param mixed               $request WP_REST_Request.
	 * @return array<string,mixed>
	 */
	public function filter_rest_post_search_query( $args, $request ) {
		unset( $request );
		if ( ! $this->should_hide_for_anon_caller() ) {
			return is_array( $args ) ? $args : array();
		}
		return $this->append_login_id_exclusion( is_array( $args ) ? $args : array() );
	}

	/**
	 * WordPress core XML sitemap query-args filter. The auto-created login
	 * page is a published `page` post and would otherwise be listed in
	 * /wp-sitemap-posts-page-1.xml — fetched by every search engine and
	 * indexer that follows the sitemap. (Audit finding M6.)
	 *
	 * @param array<string,mixed> $args      Query args used by the sitemap provider.
	 * @param string              $post_type Sitemap post type, e.g. 'page'.
	 * @return array<string,mixed>
	 */
	public function filter_sitemap_query_args( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return is_array( $args ) ? $args : array();
		}
		if ( ! $this->settings->get( 'hide_login_page_from_lists' ) ) {
			return is_array( $args ) ? $args : array();
		}
		if ( $this->login_page_id() <= 0 ) {
			return is_array( $args ) ? $args : array();
		}
		return $this->append_login_id_exclusion( is_array( $args ) ? $args : array() );
	}

	/**
	 * Front-end search query filter. Hooks `pre_get_posts` on the main
	 * search query so /?s=<keyword> does not return the login page to
	 * anonymous visitors. Admin search (wp-admin/edit.php?s=...) is
	 * intentionally NOT filtered — admins need to find the page to manage
	 * it. (Audit finding M7.)
	 *
	 * @param WP_Query $query The query about to run.
	 */
	public function filter_search_query( $query ): void {
		if ( ! ( $query instanceof WP_Query ) ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_search() ) {
			return;
		}
		if ( ! $this->should_hide_for_anon_caller() ) {
			return;
		}
		$excluded   = (array) $query->get( 'post__not_in', array() );
		$excluded   = array_map( 'intval', $excluded );
		$excluded[] = $this->login_page_id();
		$query->set( 'post__not_in', array_values( array_unique( array_filter( $excluded ) ) ) );
	}

	public function filter_navigation_link( $block_content, $block ) {
		if ( ! $this->should_hide_from_lists() ) {
			return $block_content;
		}
		if ( ! is_array( $block ) || empty( $block['attrs'] ) ) {
			return $block_content;
		}
		$attrs     = $block['attrs'];
		$linked_id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		if ( 0 === $linked_id || $linked_id !== $this->login_page_id() ) {
			return $block_content;
		}
		$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : '';
		$type = isset( $attrs['type'] ) ? (string) $attrs['type'] : '';
		if ( 'post-type' === $kind || 'page' === $type ) {
			return '';
		}
		return $block_content;
	}

	/**
	 * Whether the current request is for the auto-created login page.
	 */
	private function is_login_page_request(): bool {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}
		$id = $this->login_page_id();
		if ( $id <= 0 ) {
			return false;
		}
		return $id === get_queried_object_id();
	}

	/**
	 * Replace the theme template with our plugin-owned, theme-free template
	 * when the request is for the configured login page.
	 *
	 * @param string $template Theme-resolved template path.
	 * @return string
	 */
	public function maybe_override_template( $template ) {
		if ( ! $this->is_login_page_request() ) {
			return $template;
		}
		$override = TSSL_PLUGIN_DIR . 'includes/views/login-template.php';
		if ( file_exists( $override ) ) {
			return $override;
		}
		return $template;
	}

	/**
	 * Pre-enqueue the login stylesheet in `wp_enqueue_scripts` so it lands in
	 * `<head>` before `the_content()` (which is where the shortcode runs).
	 */
	public function maybe_preload_login_assets(): void {
		if ( ! $this->is_login_page_request() ) {
			return;
		}
		wp_enqueue_style( 'tssl-login', TSSL_PLUGIN_URL . 'assets/css/login.css', array(), TSSL_VERSION );
	}

	/**
	 * Add the portal body class so the CSS gradient + reset applies even when
	 * the page is served through the standard template-include path.
	 *
	 * @param array<int,string> $classes
	 * @return array<int,string>
	 */
	public function maybe_add_portal_body_class( $classes ) {
		if ( ! $this->is_login_page_request() ) {
			return $classes;
		}
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}
		$classes[] = 'tssl-portal-body';
		return $classes;
	}
}
