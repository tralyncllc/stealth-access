<?php
/**
 * Auto-creates the page that hosts the login shortcode and (optionally)
 * hides it from public navigation / page-list outputs.
 *
 * @package StealthAccess
 */

defined( 'ABSPATH' ) || exit;

class TSSL_Page_Manager {

	private TSSL_Settings $settings;
	private bool $renaming = false;

	public function __construct( TSSL_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'update_option_' . TSSL_Settings::OPTION_KEY, array( $this, 'maybe_rename_page' ), 10, 2 );
		add_action( 'update_option_' . TSSL_Settings::OPTION_KEY, array( $this, 'maybe_create_on_save' ), 10, 2 );

		add_filter( 'wp_list_pages_excludes', array( $this, 'filter_wp_list_pages_excludes' ) );
		add_filter( 'get_pages', array( $this, 'filter_get_pages' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_objects' ) );
		add_filter( 'render_block_core/navigation-link', array( $this, 'filter_navigation_link' ), 10, 2 );

		// Theme isolation: route the login page through a plugin-owned
		// template that omits the active theme's header/footer/sidebar.
		add_filter( 'template_include', array( $this, 'maybe_override_template' ), 99 );

		// Pre-enqueue login.css in `wp_enqueue_scripts` so it lands in <head>
		// (the shortcode render runs during `the_content()`, which is after
		// `wp_head()` has already been printed).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_preload_login_assets' ) );

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
	}

	public function maybe_create_on_save( $old, $new ): void {
		unset( $old );
		if ( ! is_array( $new ) ) {
			return;
		}
		if ( empty( $new['auto_create_login_page'] ) ) {
			return;
		}
		if ( ! empty( $new['login_page_id'] ) && get_post( (int) $new['login_page_id'] ) ) {
			return;
		}
		$this->maybe_create_login_page();
	}

	public function maybe_rename_page( $old, $new ): void {
		if ( $this->renaming ) {
			return;
		}
		if ( ! is_array( $old ) || ! is_array( $new ) ) {
			return;
		}
		$old_slug = isset( $old['custom_login_slug'] ) ? (string) $old['custom_login_slug'] : '';
		$new_slug = isset( $new['custom_login_slug'] ) ? (string) $new['custom_login_slug'] : '';
		if ( $old_slug === $new_slug ) {
			return;
		}
		$page_id = isset( $new['login_page_id'] ) ? absint( $new['login_page_id'] ) : 0;
		if ( ! $page_id ) {
			return;
		}
		$page = get_post( $page_id );
		if ( ! $page instanceof WP_Post ) {
			return;
		}
		if ( $page->post_name !== $old_slug ) {
			return;
		}
		$this->renaming = true;
		wp_update_post(
			array(
				'ID'        => $page_id,
				'post_name' => sanitize_title( $new_slug ),
			)
		);
		$this->renaming = false;
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
