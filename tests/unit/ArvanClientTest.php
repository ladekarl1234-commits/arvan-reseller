<?php
/**
 * The real upstream client (EX-103): the layer with the most external
 * uncertainty was the only one with no test at all, so the passing suite said
 * nothing whatsoever about real mode.
 *
 * The most important case here is the one two independent reviewers found: a
 * POST that times out may already have created a billable resource, so it must
 * never be blind-retried. `wp_remote_request` is scripted by the bootstrap, and
 * the call log is what proves how many attempts actually went out.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Arvan\ArvanClient;
use ArvanReseller\Arvan\ProviderError;

final class ArvanClientTest extends Arvrs_DbTestCase
{
    private function client(): ArvanClient
    {
        return new ArvanClient('secret-upstream-token');
    }

    private function calls(): array
    {
        return $GLOBALS['__arvrs_http_log'];
    }

    /** @return array headers of the nth request (1-based) */
    private function headers(int $n): array
    {
        return $this->calls()[$n - 1]['args']['headers'];
    }

    public function test_a_successful_call_returns_the_decoded_body(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(200, ['data' => ['id' => 'srv-1']])]);
        $out = $this->client()->request('GET', '/ecc/v1/servers');
        $this->assertSame(['data' => ['id' => 'srv-1']], $out);
        $this->assertCount(1, $this->calls());
    }

    public function test_a_non_json_body_yields_an_empty_array_not_a_php_warning(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(200, '<html>maintenance</html>')]);
        $this->assertSame([], $this->client()->request('GET', '/ecc/v1/servers'));
    }

    /* ------------------------------------------------------- retry policy */

    public function test_a_get_retries_a_5xx_a_bounded_number_of_times(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(503, ['message' => 'upstream down'])]);
        try {
            $this->client()->request('GET', '/ecc/v1/servers');
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('unavailable', $e->kind);
        }
        $this->assertCount(3, $this->calls(), 'one attempt plus two retries, and then it stops');
    }

    public function test_a_get_timeout_is_reported_as_a_timeout(): void
    {
        arvrs_test_http_queue([new WP_Error('http_request_failed', 'cURL error 28: Operation timed out')]);
        try {
            $this->client()->request('GET', '/ecc/v1/servers');
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('timeout', $e->kind);
            $this->assertTrue($e->retryable());
        }
    }

    /**
     * THE fix for the duplicate-paid-resource critical. A POST whose outcome is
     * unknown must be surfaced as unknown — one attempt, and a kind that tells
     * the caller to reconcile by lookup rather than to POST again.
     */
    public function test_a_post_that_times_out_is_never_blind_retried(): void
    {
        arvrs_test_http_queue([new WP_Error('http_request_failed', 'Operation timed out')]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', ['name' => 'arvrs-order-41']);
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('timeout_indeterminate', $e->kind);
            $this->assertStringContainsString('reconcile', $e->getMessage());
        }
        $this->assertCount(1, $this->calls(), 'a POST of unknown outcome must go out exactly once');
    }

    public function test_a_post_5xx_is_also_indeterminate(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(502, ['message' => 'bad gateway'])]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', ['name' => 'x']);
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('timeout_indeterminate', $e->kind);
        }
        $this->assertCount(1, $this->calls());
    }

    /** …unless the caller says it can reconcile AND supplies a dedupe key. */
    public function test_a_post_is_retried_only_with_retry_unsafe_and_an_idempotency_key(): void
    {
        arvrs_test_http_queue([new WP_Error('http_request_failed', 'Operation timed out')]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', ['name' => 'x'], [
                'retry_unsafe'    => true,
                'idempotency_key' => 'order:41',
            ]);
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('timeout_indeterminate', $e->kind);
        }
        $this->assertCount(3, $this->calls());
        $this->assertSame('order:41', $this->headers(1)['Idempotency-Key']);
    }

    public function test_retry_unsafe_without_a_key_still_sends_exactly_one_post(): void
    {
        arvrs_test_http_queue([new WP_Error('http_request_failed', 'Operation timed out')]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', [], ['retry_unsafe' => true]);
        } catch (ProviderError $e) {
            $this->assertSame('timeout_indeterminate', $e->kind);
        }
        $this->assertCount(1, $this->calls(), 'with nothing to deduplicate on, a replay is a second server');
        $this->assertArrayNotHasKey('Idempotency-Key', $this->headers(1));
    }

    public function test_a_delete_is_idempotent_and_may_be_retried(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(500, ['message' => 'boom'])]);
        try {
            $this->client()->request('DELETE', '/ecc/v1/servers/srv-1');
        } catch (ProviderError $e) {
            $this->assertSame('unavailable', $e->kind);
        }
        $this->assertCount(3, $this->calls());
    }

    /* ------------------------------------------------------ status mapping */

    public function test_402_is_a_billing_error_and_is_not_retryable(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(402, ['message' => 'insufficient credit'])]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', ['name' => 'x']);
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('billing', $e->kind);
            $this->assertFalse($e->retryable(), 'retrying an out-of-credit account only makes the customer wait');
        }
        $this->assertCount(1, $this->calls());
    }

    public function test_409_is_a_conflict_the_caller_reconciles(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(409, ['message' => 'name already taken'])]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', ['name' => 'arvrs-order-41']);
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('conflict', $e->kind);
        }
    }

    public function test_422_and_404_are_invalid_requests(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(422, ['message' => 'flavor not in region'])]);
        try {
            $this->client()->request('POST', '/ecc/v1/servers', ['flavor_id' => 'nope']);
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('invalid', $e->kind);
        }

        arvrs_test_http_queue([arvrs_test_http_response(404, ['message' => 'no such server'])]);
        try {
            $this->client()->request('GET', '/ecc/v1/servers/gone');
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('invalid', $e->kind);
        }
    }

    /** A long Retry-After is a refusal, not something to sit and wait out. */
    public function test_429_with_a_long_retry_after_fails_fast_as_rate_limited(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(429, ['message' => 'slow down'], ['Retry-After' => '30'])]);
        try {
            $this->client()->request('GET', '/ecc/v1/servers');
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('rate_limit', $e->kind);
        }
        $this->assertCount(1, $this->calls(), 'a 30-second cooldown is not waited out inside a page render');
    }

    /* ---------------------------------------------------------- auth flip */

    public function test_a_401_flips_the_auth_prefix_exactly_once_and_remembers_it(): void
    {
        arvrs_test_http_queue([
            arvrs_test_http_response(401, ['message' => 'unauthorized']),
            arvrs_test_http_response(200, ['data' => ['ok' => true]]),
        ]);

        $out = $this->client()->request('GET', '/ecc/v1/servers');

        $this->assertSame(['data' => ['ok' => true]], $out);
        $this->assertCount(2, $this->calls(), 'one flip, not a loop');
        $this->assertSame('secret-upstream-token', $this->headers(1)['Authorization']);
        $this->assertSame('Apikey secret-upstream-token', $this->headers(2)['Authorization']);
        $this->assertSame('Apikey ', get_option('arvrs_auth_prefix'), 'the working form is remembered for next time');
    }

    public function test_a_401_that_survives_the_flip_is_an_auth_error(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(401, ['message' => 'token revoked'])]);
        try {
            $this->client()->request('GET', '/ecc/v1/servers');
            $this->fail('expected a ProviderError');
        } catch (ProviderError $e) {
            $this->assertSame('auth', $e->kind);
            $this->assertFalse($e->retryable());
        }
        $this->assertSame('', (string) get_option('arvrs_auth_prefix', ''), 'a failed flip must not be remembered');
    }

    /* ------------------------------------------------------ logging hygiene */

    public function test_the_bearer_token_never_reaches_the_audit_log(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(500, [
            'message' => 'upstream rejected Authorization: Apikey secret-upstream-token',
        ])]);
        try {
            $this->client()->request('GET', '/ecc/v1/servers');
        } catch (ProviderError $e) {
            // expected
        }

        $details = (string) $this->db->get_var(
            "SELECT GROUP_CONCAT(detail) FROM " . $this->db->prefix . 'arvrs_audit_log'
        );
        $this->assertNotSame('', $details, 'the failure must be audited at all');
        $this->assertStringNotContainsString('secret-upstream-token', $details);
    }

    public function test_every_request_carries_a_correlation_id_the_caller_can_read_back(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(200, [])]);
        $client = $this->client();
        $client->request('GET', '/ecc/v1/servers');

        $cid = $client->last_correlation_id();
        $this->assertNotSame('', $cid);
        $this->assertSame($cid, $this->headers(1)['X-Correlation-Id']);
    }

    public function test_an_absolute_url_bypasses_the_default_host(): void
    {
        arvrs_test_http_queue([arvrs_test_http_response(200, [])]);
        $this->client()->request('GET', 'https://storage.arvanapis.ir/buckets');
        $this->assertSame('https://storage.arvanapis.ir/buckets', $this->calls()[0]['url']);
    }
}
