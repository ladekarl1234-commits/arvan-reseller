<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Audit\Audit;

defined('ABSPATH') || exit;

/**
 * Thin HTTP client over the WordPress HTTP API for the ArvanCloud REST API.
 * Owns: auth header, timeouts, bounded retries, status handling, error
 * normalization, correlation IDs, redacted logging (spec: Arvan API
 * abstraction). Endpoint knowledge lives in RealProvider, not here.
 */
final class ArvanClient
{
    private const BASE = 'https://napi.arvancloud.ir';
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 20;
    private const RETRIES = 2; // on 5xx / timeout only

    /** @var string */
    private $token;

    /** @var bool */
    private $prefix_retried = false;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * @param string $method GET|POST|PUT|DELETE|PATCH
     * @param string $path   path beginning with / (joined to the napi host) or an absolute https URL
     *                       (the Object Storage management API lives on storage.arvanapis.ir)
     * @param array|null $body JSON body
     * @return array decoded JSON (possibly empty)
     * @throws ProviderError
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $correlation = substr(wp_generate_uuid4(), 0, 8);
        $url  = strpos($path, 'https://') === 0 ? $path : self::BASE . $path;
        // The OpenAPI specs declare a raw apiKey Authorization header; some
        // official doc samples show an "Apikey " prefix. We remember whichever
        // form last authenticated and fall back to the other on 401 once.
        $prefix = get_option('arvrs_auth_prefix', '');
        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'headers' => [
                'Authorization' => $prefix . $this->token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'arvan-reseller/' . ARVRS_VERSION,
                'X-Correlation-Id' => $correlation,
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $attempt = 0;
        do {
            $attempt++;
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $retryable = true;
                $last_err  = $response->get_error_message();
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code === 401 && !$this->prefix_retried) {
                    // Flip between raw-key and "Apikey " prefix once, then persist the working form.
                    $this->prefix_retried = true;
                    $new = $args['headers']['Authorization'] === $this->token ? 'Apikey ' : '';
                    $args['headers']['Authorization'] = $new . $this->token;
                    $response2 = wp_remote_request($url, $args);
                    if (!is_wp_error($response2) && (int) wp_remote_retrieve_response_code($response2) < 400) {
                        update_option('arvrs_auth_prefix', $new, false);
                        return $this->handle((int) wp_remote_retrieve_response_code($response2), (string) wp_remote_retrieve_body($response2), $path, $correlation);
                    }
                }
                if ($code < 500 && $code !== 429) {
                    return $this->handle($code, (string) wp_remote_retrieve_body($response), $path, $correlation);
                }
                $retryable = true;
                $last_err  = 'HTTP ' . $code;
                if ($code === 429) {
                    // Respect Retry-After up to 5s inside the request; beyond
                    // that the caller's job retry/backoff takes over.
                    $after = (int) wp_remote_retrieve_header($response, 'retry-after');
                    if ($after > 5 || $attempt > self::RETRIES) {
                        throw new ProviderError('rate_limit', 'Arvan API rate limited', $correlation);
                    }
                    sleep(max(1, $after));
                    continue;
                }
            }
            if ($attempt <= self::RETRIES && $retryable) {
                usleep(250000 * $attempt); // 250ms, 500ms
            }
        } while ($attempt <= self::RETRIES);

        Audit::error('arvan.request_failed', ['path' => $path, 'cid' => $correlation, 'error' => $last_err]);
        $kind = (strpos((string) $last_err, 'timed out') !== false || strpos((string) $last_err, 'cURL error 28') !== false)
            ? 'timeout' : 'unavailable';
        throw new ProviderError($kind, 'Arvan API unreachable: ' . $last_err, $correlation);
    }

    /** @throws ProviderError */
    private function handle(int $code, string $body, string $path, string $correlation): array
    {
        $json = json_decode($body, true);
        if ($code >= 200 && $code < 300) {
            return is_array($json) ? $json : [];
        }

        $message = is_array($json) ? (string) ($json['message'] ?? ($json['error'] ?? 'HTTP ' . $code)) : 'HTTP ' . $code;
        Audit::error('arvan.api_error', ['path' => $path, 'code' => $code, 'cid' => $correlation, 'message' => substr($message, 0, 300)]);

        if ($code === 401 || $code === 403) {
            throw new ProviderError('auth', 'Arvan API auth failed: ' . $message, $correlation);
        }
        if ($code === 404) {
            throw new ProviderError('invalid', 'Arvan resource not found: ' . $message, $correlation);
        }
        if ($code === 422 || $code === 400) {
            throw new ProviderError('invalid', 'Arvan rejected request: ' . $message, $correlation);
        }
        throw new ProviderError('unknown', 'Arvan API error ' . $code . ': ' . $message, $correlation);
    }
}
