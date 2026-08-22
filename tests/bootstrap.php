<?php
/**
 * Unit-test bootstrap: WordPress shims wide enough that the plugin's DB paths
 * — the ledger, the order claim, the renewal clock, the job queue, the schema
 * migrations — load and run without a WordPress install.
 *
 * The `$wpdb` seam lives in tests/support/FakeWpdb.php (SQLite-backed) and its
 * header states plainly what that double does and does not reproduce. Tests
 * that need it extend Arvrs_DbTestCase; the pure-domain tests do not touch it.
 */

// Direct-access guard (EX-116): ABSPATH does not exist yet at this point, so
// the SAPI is what distinguishes PHPUnit from a web request into the plugin
// directory. Nothing below may be defined in a web context.
php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg' || exit;

define('ABSPATH', sys_get_temp_dir() . '/arvrs-fake-wp/');
define('ARVRS_DIR', dirname(__DIR__) . '/');
define('ARVRS_VERSION', 'test');
define('ARVRS_SCHEMA_VERSION', 5);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MINUTE_IN_SECONDS', 60);
define('WEEK_IN_SECONDS', 604800);
define('MB_IN_BYTES', 1048576);
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

// Schema::migrate() require_once's this before calling dbDelta(); dbDelta
// itself is shimmed below, so the file only has to exist.
$arvrs_upgrade_stub = ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_file($arvrs_upgrade_stub)) {
    @mkdir(dirname($arvrs_upgrade_stub), 0777, true);
    file_put_contents($arvrs_upgrade_stub, "<?php // dbDelta() is provided by tests/bootstrap.php\n");
}

/* ------------------------------------------------------------ state stores */

$GLOBALS['__arvrs_options']    = [];
$GLOBALS['__arvrs_transients'] = [];
$GLOBALS['__arvrs_usermeta']   = [];
$GLOBALS['__arvrs_cache']      = [];
$GLOBALS['__arvrs_hooks']      = [];
$GLOBALS['__arvrs_mail']       = [];
$GLOBALS['__arvrs_users']      = [];
$GLOBALS['__arvrs_current_user'] = 0;
$GLOBALS['__arvrs_caps']       = [];
$GLOBALS['__arvrs_roles']      = [];
$GLOBALS['__arvrs_rest_routes'] = [];

/** Reset every shim store. Called by Arvrs_DbTestCase::setUp(). */
function arvrs_test_reset_state(): void
{
    foreach (['options', 'transients', 'usermeta', 'cache', 'hooks', 'mail', 'users', 'caps', 'roles', 'rest_routes'] as $store) {
        $GLOBALS['__arvrs_' . $store] = [];
    }
    $GLOBALS['__arvrs_current_user'] = 0;
}

/* ----------------------------------------------------------------- options */

function get_option(string $key, $default = false)
{
    return $GLOBALS['__arvrs_options'][$key] ?? $default;
}
function update_option(string $key, $value, $autoload = null): bool
{
    $GLOBALS['__arvrs_options'][$key] = $value;
    Arvrs_FakeWpdb::mirror_option($key, $value);
    return true;
}
function add_option(string $key, $value = '', string $deprecated = '', $autoload = null): bool
{
    if (isset($GLOBALS['__arvrs_options'][$key])) {
        return false;
    }
    return update_option($key, $value);
}
function delete_option(string $key): bool
{
    unset($GLOBALS['__arvrs_options'][$key]);
    Arvrs_FakeWpdb::mirror_delete($key);
    return true;
}
function maybe_unserialize($value)
{
    if (!is_string($value)) {
        return $value;
    }
    $trimmed = trim($value);
    if ($trimmed === 'b:0;') {
        return false;
    }
    $out = @unserialize($trimmed);
    return $out === false ? $value : $out;
}

/* -------------------------------------------------------------- transients */

function get_transient(string $key)
{
    $row = $GLOBALS['__arvrs_transients'][$key] ?? null;
    if ($row === null) {
        return false;
    }
    if ($row['expires'] > 0 && $row['expires'] < time()) {
        unset($GLOBALS['__arvrs_transients'][$key]);
        return false;
    }
    return $row['value'];
}
function set_transient(string $key, $value, int $ttl = 0): bool
{
    $GLOBALS['__arvrs_transients'][$key] = ['value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0];
    return true;
}
function delete_transient(string $key): bool
{
    unset($GLOBALS['__arvrs_transients'][$key]);
    return true;
}

/* ------------------------------------------------------------ object cache */

function wp_using_ext_object_cache(): bool
{
    return false; // Helpers::rate_limit's transient fallback is what ships by default.
}
function wp_cache_get($key, $group = '', $force = false, &$found = null)
{
    $found = isset($GLOBALS['__arvrs_cache'][$group][$key]);
    return $found ? $GLOBALS['__arvrs_cache'][$group][$key] : false;
}
function wp_cache_set($key, $value, $group = '', $ttl = 0): bool
{
    $GLOBALS['__arvrs_cache'][$group][$key] = $value;
    return true;
}
function wp_cache_add($key, $value, $group = '', $ttl = 0): bool
{
    if (isset($GLOBALS['__arvrs_cache'][$group][$key])) {
        return false;
    }
    return wp_cache_set($key, $value, $group, $ttl);
}
function wp_cache_incr($key, $offset = 1, $group = '')
{
    if (!isset($GLOBALS['__arvrs_cache'][$group][$key])) {
        return false;
    }
    $GLOBALS['__arvrs_cache'][$group][$key] += $offset;
    return $GLOBALS['__arvrs_cache'][$group][$key];
}
function wp_cache_delete($key, $group = ''): bool
{
    unset($GLOBALS['__arvrs_cache'][$group][$key]);
    return true;
}

/* -------------------------------------------------------------- hooks (real) */

function add_filter(string $tag, $callback, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['__arvrs_hooks'][$tag][$priority][] = $callback;
    return true;
}
function add_action(string $tag, $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($tag, $callback, $priority, $args);
}
function remove_action(string $tag, $callback, int $priority = 10): bool
{
    if (isset($GLOBALS['__arvrs_hooks'][$tag][$priority])) {
        foreach ($GLOBALS['__arvrs_hooks'][$tag][$priority] as $i => $registered) {
            if ($registered === $callback) {
                unset($GLOBALS['__arvrs_hooks'][$tag][$priority][$i]);
            }
        }
    }
    return true;
}
function remove_filter(string $tag, $callback, int $priority = 10): bool
{
    return remove_action($tag, $callback, $priority);
}
function apply_filters(string $tag, $value, ...$args)
{
    $hooks = $GLOBALS['__arvrs_hooks'][$tag] ?? [];
    ksort($hooks);
    foreach ($hooks as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = call_user_func_array($callback, array_merge([$value], $args));
        }
    }
    return $value;
}
function do_action(string $tag, ...$args): void
{
    $hooks = $GLOBALS['__arvrs_hooks'][$tag] ?? [];
    ksort($hooks);
    foreach ($hooks as $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func_array($callback, $args);
        }
    }
}
function has_filter(string $tag, $callback = false): bool
{
    return !empty($GLOBALS['__arvrs_hooks'][$tag]);
}

/* ------------------------------------------------------------------ users */

/** Register a fake user so ownership and capability shims have something to read. */
function arvrs_test_add_user(int $id, array $props = []): int
{
    $GLOBALS['__arvrs_users'][$id] = array_merge(
        ['ID' => $id, 'user_email' => 'user' . $id . '@example.test', 'display_name' => 'user' . $id],
        $props
    );
    return $id;
}
function get_userdata($user_id)
{
    $row = $GLOBALS['__arvrs_users'][(int) $user_id] ?? null;
    if (!$row) {
        return false;
    }
    $row['roles'] = (array) ($row['roles'] ?? []);
    return (object) $row;
}
function wp_get_current_user()
{
    $user = get_userdata(get_current_user_id());
    return $user ?: (object) ['ID' => 0, 'roles' => [], 'user_email' => ''];
}
function get_role($role)
{
    return $GLOBALS['__arvrs_roles'][$role] ?? null;
}
function add_role($role, $display, $caps = [])
{
    $GLOBALS['__arvrs_roles'][$role] = (object) ['name' => $role, 'capabilities' => $caps];
    return $GLOBALS['__arvrs_roles'][$role];
}
function wp_doing_ajax(): bool
{
    return false;
}
function wp_get_referer()
{
    return false;
}
function remove_query_arg($keys, $url = '')
{
    return (string) $url;
}
function get_user_by($field, $value)
{
    foreach ($GLOBALS['__arvrs_users'] as $row) {
        if ((string) ($row[$field === 'email' ? 'user_email' : $field] ?? '') === (string) $value) {
            return (object) $row;
        }
    }
    return false;
}
function wp_set_current_user($user_id)
{
    $GLOBALS['__arvrs_current_user'] = (int) $user_id;
    return get_userdata($user_id);
}
function get_current_user_id(): int
{
    return (int) $GLOBALS['__arvrs_current_user'];
}
function is_user_logged_in(): bool
{
    return get_current_user_id() > 0;
}
function current_user_can(string $capability): bool
{
    $caps = $GLOBALS['__arvrs_caps'][get_current_user_id()] ?? [];
    return in_array($capability, $caps, true);
}
/** Grant capabilities to a fake user (the missing-capability tests revoke them). */
function arvrs_test_grant(int $user_id, array $caps): void
{
    $GLOBALS['__arvrs_caps'][$user_id] = $caps;
}
function get_user_meta($user_id, string $key = '', bool $single = false)
{
    $value = $GLOBALS['__arvrs_usermeta'][(int) $user_id][$key] ?? '';
    return $single ? $value : ($value === '' ? [] : [$value]);
}
function update_user_meta($user_id, string $key, $value): bool
{
    $GLOBALS['__arvrs_usermeta'][(int) $user_id][$key] = $value;
    return true;
}
function delete_user_meta($user_id, string $key): bool
{
    unset($GLOBALS['__arvrs_usermeta'][(int) $user_id][$key]);
    return true;
}
function wp_mail($to, $subject, $message, $headers = '', $attachments = []): bool
{
    $GLOBALS['__arvrs_mail'][] = ['to' => $to, 'subject' => $subject, 'message' => $message];
    return true;
}

/* ------------------------------------------------------------ errors & misc */

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string,string[]> */
        private $errors = [];
        /** @var array */
        private $data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
                $this->data[$code]     = $data;
            }
        }
        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return $codes ? $codes[0] : '';
        }
        public function get_error_message($code = '')
        {
            $code = $code ?: $this->get_error_code();
            return $this->errors[$code][0] ?? '';
        }
        public function add_data($data, $code = ''): void
        {
            $this->data[$code ?: $this->get_error_code()] = $data;
        }
        public function get_error_data($code = '')
        {
            return $this->data[$code ?: $this->get_error_code()] ?? null;
        }
    }
}
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

/**
 * Redirects and nonce failures terminate the request in WordPress. A test
 * cannot survive `exit`, so both are raised as exceptions — which is also what
 * lets the negative-auth tests assert that a guard fired BEFORE the side
 * effect it protects (EX-071).
 */
class Arvrs_Test_Redirect extends \RuntimeException
{
    /** @var string */
    public $url;
    public function __construct(string $url)
    {
        $this->url = $url;
        parent::__construct('redirect: ' . $url);
    }
}
class Arvrs_Test_NonceFailure extends \RuntimeException
{
}

function wp_safe_redirect($url, $status = 302): bool
{
    throw new Arvrs_Test_Redirect((string) $url);
}
function wp_redirect($url, $status = 302): bool
{
    throw new Arvrs_Test_Redirect((string) $url);
}
function wp_create_nonce(string $action = '-1'): string
{
    return substr(hash_hmac('sha256', $action, wp_salt('nonce')), 0, 10);
}
function wp_verify_nonce($nonce, string $action = '-1')
{
    return hash_equals(wp_create_nonce($action), (string) $nonce) ? 1 : false;
}
function check_admin_referer($action = -1, $query_arg = '_wpnonce')
{
    $given = isset($_POST[$query_arg]) ? (string) $_POST[$query_arg] : (string) ($_GET[$query_arg] ?? '');
    if (!wp_verify_nonce($given, (string) $action)) {
        throw new Arvrs_Test_NonceFailure('nonce check failed for ' . $action);
    }
    return 1;
}
function check_ajax_referer($action = -1, $query_arg = false, $die = true)
{
    return check_admin_referer($action, $query_arg ?: '_wpnonce');
}
function wp_die($message = '', $title = '', $args = [])
{
    throw new \RuntimeException(is_string($message) ? $message : 'wp_die');
}
function wp_logout(): void
{
    $GLOBALS['__arvrs_current_user'] = 0;
}
function wp_unslash($value)
{
    return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
}
function add_query_arg(...$args): string
{
    $url = is_array($args[0]) ? ($args[1] ?? '') : ($args[2] ?? '');
    $add = is_array($args[0]) ? $args[0] : [$args[0] => $args[1]];
    $sep = strpos((string) $url, '?') === false ? '?' : '&';
    return $url . $sep . http_build_query($add);
}
function is_ssl(): bool
{
    return false;
}
function is_admin(): bool
{
    return false;
}
function absint($value): int
{
    return abs((int) $value);
}
function is_email($email)
{
    return filter_var((string) $email, FILTER_VALIDATE_EMAIL) ? $email : false;
}
function sanitize_email($email): string
{
    return (string) filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL);
}
function number_format_i18n($number, $decimals = 0): string
{
    return number_format((float) $number, (int) $decimals);
}
function wp_timezone(): \DateTimeZone
{
    return new \DateTimeZone('UTC');
}
function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}
function home_url(string $path = ''): string
{
    return 'https://example.test/' . ltrim($path, '/');
}
function rest_url(string $path = ''): string
{
    return 'https://example.test/wp-json/' . ltrim($path, '/');
}

/**
 * Capture route registrations instead of dispatching them. That makes each
 * route's `permission_callback` and `args` schema directly assertable, which
 * is what the negative-authz tests need: a callback silently changed to
 * `__return_true` is a one-token regression (EX-071).
 */
function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
{
    $GLOBALS['__arvrs_rest_routes'][$namespace . $route] = $args;
    return true;
}
function rest_ensure_response($response)
{
    return $response;
}
function __return_true(): bool
{
    return true;
}
function __return_false(): bool
{
    return false;
}
function get_permalink($post = 0)
{
    return 'https://example.test/?p=' . (int) (is_object($post) ? $post->ID : $post);
}

/* ------------------------------------------------------- escaping & i18n */

function wp_salt(string $scheme = 'auth'): string
{
    return 'unit-test-salt-' . $scheme;
}
function __($text, $domain = 'default')
{
    return $text;
}
function _e($text, $domain = 'default'): void
{
    echo $text;
}
function _n($single, $plural, $number, $domain = 'default')
{
    return $number == 1 ? $single : $plural;
}
function _x($text, $context, $domain = 'default')
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function esc_html__($text, $domain = 'default')
{
    return esc_html($text);
}
function esc_html_e($text, $domain = 'default'): void
{
    echo esc_html($text);
}
function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function esc_attr__($text, $domain = 'default')
{
    return esc_attr($text);
}
function esc_url($url)
{
    return htmlspecialchars((string) $url, ENT_QUOTES);
}
function esc_url_raw($url)
{
    return (string) $url;
}
function wp_kses_post($text)
{
    return (string) $text;
}
function wp_strip_all_tags($text)
{
    return strip_tags((string) $text);
}
function wp_specialchars_decode($text, $quote_style = ENT_NOQUOTES)
{
    return htmlspecialchars_decode((string) $text, $quote_style);
}
function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}
function sanitize_text_field($str)
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
}
function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}
function sanitize_textarea_field($str)
{
    return trim(strip_tags((string) $str));
}
function wp_generate_uuid4()
{
    return bin2hex(random_bytes(16));
}
function current_time(string $type, $gmt = 0)
{
    return gmdate('Y-m-d H:i:s');
}

/* ------------------------------------------------------------- HTTP stub */

/**
 * `wp_remote_request` answers from a scripted queue and records every call, so
 * the upstream client's retry policy, status mapping and header handling can be
 * asserted without a network (EX-103). Queue entries are either a response
 * array (`['response' => ['code' => 500], 'body' => '…']`) or a WP_Error.
 * An exhausted queue repeats its last entry — a test that means "always 500"
 * should not have to say it three times.
 */
$GLOBALS['__arvrs_http_queue'] = [];
$GLOBALS['__arvrs_http_log']   = [];

function arvrs_test_http_queue(array $responses): void
{
    $GLOBALS['__arvrs_http_queue'] = $responses;
    $GLOBALS['__arvrs_http_log']   = [];
}
function arvrs_test_http_response(int $code, $body = [], array $headers = []): array
{
    return [
        'response' => ['code' => $code, 'message' => ''],
        'body'     => is_string($body) ? $body : (string) json_encode($body),
        'headers'  => $headers,
    ];
}
function wp_remote_request($url, $args = [])
{
    $GLOBALS['__arvrs_http_log'][] = ['url' => $url, 'args' => $args];
    $queue = &$GLOBALS['__arvrs_http_queue'];
    if (!$queue) {
        return arvrs_test_http_response(200, []);
    }
    return count($queue) > 1 ? array_shift($queue) : $queue[0];
}
function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? ($response['response']['code'] ?? 0) : 0;
}
function wp_remote_retrieve_body($response)
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}
function wp_remote_retrieve_header($response, $name)
{
    if (!is_array($response)) {
        return '';
    }
    foreach ((array) ($response['headers'] ?? []) as $key => $value) {
        if (strcasecmp((string) $key, (string) $name) === 0) {
            return $value;
        }
    }
    return '';
}

/* ------------------------------------------------------------------ $wpdb */

require_once __DIR__ . '/support/FakeWpdb.php';

/** dbDelta() is the one WordPress function the schema genuinely needs. */
function dbDelta($queries = '', $execute = true): array
{
    global $wpdb;
    $out = [];
    foreach ((array) $queries as $sql) {
        $out = array_merge($out, $wpdb->db_delta((string) $sql));
    }
    return $out;
}

$GLOBALS['wpdb'] = new Arvrs_FakeWpdb();

/* ------------------------------------------------------------- autoloading */

spl_autoload_register(static function ($class) {
    if (strpos($class, 'ArvanReseller\\') !== 0) {
        return;
    }
    $file = ARVRS_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen('ArvanReseller\\'))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
require_once ARVRS_DIR . 'src/Arvan/DTO.php';
if (class_exists("PHPUnit\\Framework\\TestCase")) {
    require_once __DIR__ . "/support/DbTestCase.php";
}
