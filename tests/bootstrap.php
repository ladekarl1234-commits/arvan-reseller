<?php
/**
 * Unit-test bootstrap: minimal WordPress shims so the plugin's pure domain
 * classes load without a WordPress install. Anything touching $wpdb is
 * exercised in wp-env integration runs, not here (docs/TESTING.md).
 */

define('ABSPATH', sys_get_temp_dir() . '/arvrs-fake-wp/');
define('ARVRS_DIR', dirname(__DIR__) . '/');
define('ARVRS_VERSION', 'test');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MB_IN_BYTES', 1048576);

/** In-memory option store. */
$GLOBALS['__arvrs_options'] = [];

function get_option(string $key, $default = false)
{
    return $GLOBALS['__arvrs_options'][$key] ?? $default;
}
function update_option(string $key, $value, $autoload = null): bool
{
    $GLOBALS['__arvrs_options'][$key] = $value;
    return true;
}
function add_option(string $key, $value = '', string $deprecated = '', $autoload = null): bool
{
    if (isset($GLOBALS['__arvrs_options'][$key])) {
        return false;
    }
    $GLOBALS['__arvrs_options'][$key] = $value;
    return true;
}
function delete_option(string $key): bool
{
    unset($GLOBALS['__arvrs_options'][$key]);
    return true;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'unit-test-salt-' . $scheme;
}
function __($text, $domain = 'default')
{
    return $text;
}
function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
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

/**
 * Minimal fake $wpdb — just enough for read-only lookups (BaseCosts::get)
 * used by pure-ish code paths under test. Anything more belongs in wp-env
 * integration tests, not here.
 */
class Arvrs_Fake_Wpdb
{
    public $prefix = 'wp_';
    /** @var mixed value returned by get_var */
    public $var_result = 0;

    public function prepare($query, ...$args)
    {
        return $query;
    }

    public function get_var($query)
    {
        return $this->var_result;
    }
}
$GLOBALS['wpdb'] = new Arvrs_Fake_Wpdb();

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
