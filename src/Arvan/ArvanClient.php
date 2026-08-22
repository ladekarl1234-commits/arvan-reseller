<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Audit\Audit;

defined('ABSPATH') || exit;

/**
 * Thin HTTP client over the WordPress HTTP API for the ArvanCloud REST API.
 * Owns: auth header, timeouts, VERB-AWARE bounded retries, status handling,
 * error normalization, correlation IDs, redacted logging (spec: Arvan API
 * abstraction). Endpoint knowledge lives in RealProvider, not here.
 *
 * The retry policy is the safety-critical part. A POST that times out may
 * already have created a billable resource upstream, so repeating it is how a
 * customer who paid once ends up with two servers on the reseller's Arvan
 * invoice. Idempotent verbs retry; POST/PATCH do not, and surface the honest
 * answer instead: `timeout_indeterminate`, for the caller to reconcile by
 * looking the resource up under its deterministic name.
 */
final class ArvanClient
{
    private const BASE = 'https://napi.arvancloud.ir';
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 20;
    private const RETRIES = 2; // idempotent verbs only, on 5xx / timeout / 429

    /** Verbs the HTTP spec defines as idempotent — safe to replay verbatim. */
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** @var string */
    private $token;

    /** @var bool */
    private $prefix_retried = false;

    /** @var string correlation id of the most recent request (success or not) */
    private $last_correlation_id = '';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** Correlation id of the last call, so callers can record it on success. */
    public function last_correlation_id(): string
    {
        return $this->last_correlation_id;
    }

    /**
     * @param string $method GET|POST|PUT|DELETE|PATCH
     * @param string $path   path beginning with / (joined to the napi host) or an absolute https URL
     *                       (the Object Storage management API lives on storage.arvanapis.ir)
     * @param array|null $body JSON body
     * @param array $opts {
     *   @type string $idempotency_key sent as the `Idempotency-Key` header when non-empty
     *   @type bool   $retry_unsafe    allow retrying POST/PATCH — only legitimate when the
     *                                 caller can reconcile by lookup AND a key is supplied
     * }
     * @return array decoded JSON (possibly empty)
     * @throws ProviderError
     */
    public function request(string $method, string $path, ?array $body = null, array $opts = []): array
    {
        $method      = strtoupper($method);
        $correlation = substr(wp_generate_uuid4(), 0, 8);
        $this->last_correlation_id = $correlation;
        $url = strpos($path, 'https://') === 0 ? $path : self::BASE . $path;

        // The OpenAPI specs declare a raw apiKey Authorization header; some
        // official doc samples show an "Apikey " prefix. We remember whichever
        // form last authenticated and fall back to the other on 401 once.
        $prefix = get_option('arvrs_auth_prefix', '');
        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => $prefix . $this->token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'arvan-reseller/' . ARVRS_VERSION,
                'X-Correlation-Id' => $correlation,
            ],
        ];
        $key = substr((string) ($opts['idempotency_key'] ?? ''), 0, 128);
        if ($key !== '') {
            $args['headers']['Idempotency-Key'] = $key;
        }
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $safe = in_array($method, self::IDEMPOTENT, true);
        // An unsafe verb may only be replayed when the caller both asked for it
        // and gave us a key to deduplicate on. Without both, one attempt only.
        $may_retry = $safe || (!empty($opts['retry_unsafe']) && $key !== '');
        $max       = $may_retry ? self::RETRIES : 0;

        $started  = microtime(true);
        $attempt  = 0;
        $last_err = '';
        do {
            $attempt++;
            $response = $this->send($url, $args);

            if (is_wp_error($response)) {
                $last_err = $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code === 401 && !$this->prefix_retried) {
                    // A 401 means the request was rejected, not executed, so
                    // re-issuing it is safe even for POST.
                    $this->prefix_retried = true;
                    $new = $args['headers']['Authorization'] === $this->token ? 'Apikey ' : '';
                    $args['headers']['Authorization'] = $new . $this->token;
                    $response2 = $this->send($url, $args);
                    if (!is_wp_error($response2) && (int) wp_remote_retrieve_response_code($response2) < 400) {
                        update_option('arvrs_auth_prefix', $new, false);
                        return $this->handle((int) wp_remote_retrieve_response_code($response2), (string) wp_remote_retrieve_body($response2), $method, $path, $correlation, $started);
                    }
                }
                if ($code < 500 && $code !== 429) {
                    return $this->handle($code, (string) wp_remote_retrieve_body($response), $method, $path, $correlation, $started);
                }
                $last_err = 'HTTP ' . $code;
                if ($code === 429) {
                    // A 429 was throttled, not executed — nothing happened
                    // upstream, so it is never indeterminate.
                    $after = (int) wp_remote_retrieve_header($response, 'retry-after');
                    if (!$may_retry || $after > 5 || $attempt > $max) {
                        $this->log_failure($method, $path, $correlation, $started, $last_err);
                        throw new ProviderError('rate_limit', 'Arvan API rate limited', $correlation);
                    }
                    // usleep, never sleep(): this can run inside a page render.
                    usleep((int) min(max(1, $after), 2) * 1000000);
                    continue;
                }
            }
            if ($attempt <= $max) {
                usleep(250000 * $attempt); // 250ms, 500ms
            }
        } while ($attempt <= $max);

        $this->log_failure($method, $path, $correlation, $started, $last_err);

        if (!$safe) {
            // THE fix for the duplicate-paid-resource critical: we do not know
            // whether Arvan performed this write, so we refuse to guess. The
            // caller reconciles by deterministic name instead of re-POSTing.
            throw new ProviderError(
                'timeout_indeterminate',
                'Arvan ' . $method . ' outcome unknown (' . $last_err . ') — reconcile before retrying',
                $correlation
            );
        }
        $kind = (strpos((string) $last_err, 'timed out') !== false || strpos((string) $last_err, 'cURL error 28') !== false)
            ? 'timeout' : 'unavailable';
        throw new ProviderError($kind, 'Arvan API unreachable: ' . $last_err, $correlation);
    }

    /**
     * WordPress forwards only timeout/useragent/blocking/hooks into Requests,
     * so a `connect_timeout` argument is silently ignored. The cURL hook is the
     * only place the connect phase can actually be bounded.
     */
    private function send(string $url, array $args)
    {
        $connect = static function ($handle) {
            if ($handle && function_exists('curl_setopt')) {
                @curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            }
        };
        add_action('http_api_curl', $connect, 10, 1);
        try {
            return wp_remote_request($url, $args);
        } finally {
            remove_action('http_api_curl', $connect, 10);
        }
    }

    /** @throws ProviderError */
    private function handle(int $code, string $body, string $method, string $path, string $correlation, float $started): array
    {
        $json = json_decode($body, true);
        if ($code >= 200 && $code < 300) {
            $this->log_debug($method, $path, $code, $correlation, $started, is_array($json) ? $json : []);
            return is_array($json) ? $json : [];
        }

        $message = is_array($json) ? (string) ($json['message'] ?? ($json['error'] ?? 'HTTP ' . $code)) : 'HTTP ' . $code;
        // Upstream free text is where a provider is most likely to echo a
        // header or signed URL back at us, so it is scrubbed by VALUE here —
        // Audit::redact() only matches key names.
        $message = self::scrub($message);
        Audit::error('arvan.api_error', ['method' => $method, 'path' => $path, 'code' => $code, 'cid' => $correlation, 'message' => substr($message, 0, 300)]);
        $this->log_debug($method, $path, $code, $correlation, $started, is_array($json) ? $json : []);

        if ($code === 401 || $code === 403) {
            throw new ProviderError('auth', 'Arvan API auth failed: ' . $message, $correlation);
        }
        if ($code === 402) {
            // Upstream account is out of credit. Retrying cannot help and each
            // attempt costs the customer another minute of waiting.
            throw new ProviderError('billing', 'Arvan account billing rejected the request: ' . $message, $correlation);
        }
        if ($code === 409) {
            // "Already exists" — the caller reconciles instead of re-creating.
            throw new ProviderError('conflict', 'Arvan resource already exists: ' . $message, $correlation);
        }
        if ($code === 404) {
            throw new ProviderError('invalid', 'Arvan resource not found: ' . $message, $correlation);
        }
        if ($code === 422 || $code === 400) {
            throw new ProviderError('invalid', 'Arvan rejected request: ' . $message, $correlation);
        }
        throw new ProviderError('unknown', 'Arvan API error ' . $code . ': ' . $message, $correlation);
    }

    private function log_failure(string $method, string $path, string $correlation, float $started, string $error): void
    {
        Audit::error('arvan.request_failed', [
            'method' => $method,
            'path'   => $path,
            'cid'    => $correlation,
            'ms'     => (int) round((microtime(true) - $started) * 1000),
            'error'  => substr(self::scrub($error), 0, 300),
        ]);
    }

    /**
     * Successful calls are logged too (WP_DEBUG only) — without them a
     * correlation id can be traced up to the moment things worked and no
     * further, which is exactly the "it succeeded but looks wrong" case.
     */
    private function log_debug(string $method, string $path, int $code, string $correlation, float $started, array $payload): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        Audit::log(0, 'arvan.request', 'arvan', $correlation, [
            'method' => $method,
            'path'   => $path,
            'code'   => $code,
            'ms'     => (int) round((microtime(true) - $started) * 1000),
            'body'   => self::redact_body($payload),
        ], 'debug');
    }

    /** Replace secret-looking values anywhere in a decoded body. */
    private static function redact_body($value, int $depth = 0)
    {
        if (is_array($value)) {
            if ($depth > 4) {
                return '[truncated]';
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = preg_match('/(token|key|secret|password|authorization)/i', (string) $k)
                    ? '[redacted]'
                    : self::redact_body($v, $depth + 1);
            }
            return $out;
        }
        return is_string($value) ? substr(self::scrub($value), 0, 300) : $value;
    }

    /** Value-side redaction: auth schemes, key=value pairs, high-entropy blobs. */
    private static function scrub(string $text): string
    {
        $text = preg_replace('/\b(Bearer|Apikey|Basic)\s+\S+/i', '$1 [redacted]', $text);
        $text = preg_replace('/(?i)\b(token|api[_-]?key|apikey|secret|password|authorization)\b\s*[:=]\s*"?[^"\s,;}]+/', '$1=[redacted]', $text);
        $text = preg_replace('/\b[A-Za-z0-9]{32,}\b/', '[redacted]', $text);
        return (string) $text;
    }
}
