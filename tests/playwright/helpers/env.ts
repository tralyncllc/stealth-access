export const WP_BASE_URL = process.env.WP_BASE_URL as string;
export const WP_ADMIN_USER = process.env.WP_ADMIN_USER as string;
export const WP_ADMIN_PASS = process.env.WP_ADMIN_PASS as string;

// Shell-prefix used to invoke wp-cli against the WordPress under test.
// Locally this is `docker exec` into the dev container; in CI it's `docker
// compose exec` into the CI service. Override either via WP_CLI_CMD (full
// prefix) or the discrete WP_CONTAINER / WP_PATH knobs.
const WP_CONTAINER = process.env.WP_CONTAINER ?? 'plugin-app';
const WP_PATH = process.env.WP_PATH ?? '/var/www/html';
export const WP_CLI_CMD =
  process.env.WP_CLI_CMD ??
  `docker exec -i -u www-data ${WP_CONTAINER} wp --path=${WP_PATH}`;
