<?php
/**
 * `Provisioning\Provisioner` result kinds and its four idempotency layers.
 *
 * The kinds matter more than they look: the job runner used to decide
 * retry-versus-success by `strpos()` on a message that is Persian in half the
 * paths, so a permanently misconfigured credential burned five attempts and an
 * order frozen mid-provision was reported as a success. Every kind therefore
 * gets an assertion, and so does the handler's branch on it.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Jobs\Handlers;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;

final class ProvisionerTest extends Arvrs_DbTestCase
{
    public function test_a_paid_order_is_provisioned_and_becomes_active(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PAID);

        $result = Provisioner::provision($order_id);

        $this->assertTrue($result['ok']);
        $this->assertSame('provisioned', $result['kind']);
        $this->assertGreaterThan(0, $result['service_id']);
        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status']);
        $this->assertNotSame('', (string) Services::by_order($order_id)['remote_id']);
    }

    public function test_an_unknown_order_is_not_found_rather_than_a_fatal(): void
    {
        $result = Provisioner::provision(999999);
        $this->assertSame('not_found', $result['kind']);
        $this->assertFalse($result['ok']);
    }

    /**
     * EX-074's expensive regression: the callback enqueues `provision_order`
     * AND provisions inline. When the queued job runs afterwards it must find
     * the service row and stop — never buy a second cloud server.
     */
    public function test_provisioning_the_same_order_twice_creates_exactly_one_service(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PAID);

        $first  = Provisioner::provision($order_id);
        $second = Provisioner::provision($order_id);

        $this->assertSame('provisioned', $first['kind']);
        $this->assertSame('already', $second['kind'], 'a second attempt must never reach the provider');
        $this->assertSame(1, $this->count_rows('services', 'order_id = ' . $order_id));
        $this->assertSame($first['service_id'], $second['service_id']);
    }

    public function test_the_queued_job_after_a_successful_inline_run_is_a_no_op(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PAID);
        Provisioner::provision($order_id);

        Handlers::register();
        JobRunner::enqueue('provision_order', ['order_id' => $order_id]);
        JobRunner::run_due();

        $this->assertSame(1, $this->count_rows('services', 'order_id = ' . $order_id));
        $this->assertSame(0, $this->count_rows('jobs', "status = 'dead'"), 'an idempotent no-op is not a failure');
    }

    /** A cancelled order will never provision; saying so once beats five retries. */
    public function test_a_terminal_order_is_reported_as_already_done(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::CANCELLED);
        $result = Provisioner::provision($order_id);
        $this->assertSame('already', $result['kind']);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $this->count_rows('services'));
    }

    /** An order nothing can claim from is `not_claimable`, and that is retryable. */
    public function test_an_order_in_an_unclaimable_state_is_not_reported_as_success(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PENDING_PAYMENT);

        $result = Provisioner::provision($order_id);
        $this->assertSame('not_claimable', $result['kind']);
        $this->assertFalse($result['ok']);
        $this->assertSame(StateMachine::PENDING_PAYMENT, OrderService::get($order_id)['status']);

        Handlers::register();
        $this->expectException(\RuntimeException::class);
        Handlers::provision_order(['order_id' => $order_id]); // retryable → the runner backs off
    }

    /**
     * A transient provider fault leaves the order recoverable and does NOT
     * email the customer — most of these self-heal on the next attempt, and
     * the queue alerts the admin if they do not.
     */
    public function test_a_transient_provider_failure_is_retryable_and_the_retry_succeeds(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, StateMachine::PAID, [
            'config' => '{"region":"ir-thr-simin","image":"ubuntu-24.04","name":"demo-fail"}',
        ]);

        $result = Provisioner::provision($order_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('retryable', $result['kind']);
        $this->assertSame(StateMachine::PROVISION_FAILED, OrderService::get($order_id)['status']);
        $this->assertSame(0, $this->count_rows('services'), 'a failed create must not leave a phantom service');
        $this->assertSame(0, $this->count_rows('notifications', "customer_id = " . $customer),
            'a fault that will self-heal must not alarm the buyer');

        // The demo provider fails exactly once per key, so this is the real
        // provision_failed → provisioning → active recovery path.
        $retry = Provisioner::provision($order_id);
        $this->assertSame('provisioned', $retry['kind']);
        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status']);
    }

    /**
     * A permanent fault is terminal, and the customer — who has paid — must be
     * told. Telling only the admin is what left buyers refreshing a dashboard
     * that never changed.
     */
    public function test_a_permanent_provider_failure_tells_the_customer_as_well_as_the_admin(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, StateMachine::PAID);
        $this->use_provider_that_throws(new \ArvanReseller\Arvan\ProviderError('invalid', 'flavor not available in region'));

        $result = Provisioner::provision($order_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['kind']);
        $this->assertNotSame('', $result['message'], 'the customer-facing message must not be empty');
        $this->assertStringNotContainsString('flavor not available', $result['message'], 'no raw upstream text reaches the buyer');
        $this->assertSame(StateMachine::PROVISION_FAILED, OrderService::get($order_id)['status']);
        $this->assertSame(1, $this->count_rows('notifications', "customer_id = 0 AND type = 'provision_failed'"));
        $this->assertSame(1, $this->count_rows('notifications', "customer_id = " . $customer . " AND type = 'provision_failed'"));
    }

    /** An auth failure additionally marks the credential unhealthy. */
    public function test_an_auth_failure_records_the_credential_as_broken(): void
    {
        $customer = $this->customer();
        $this->go_live();
        [$order_id] = $this->seed_order($customer, 1200000, StateMachine::PAID);
        $this->use_provider_that_throws(new \ArvanReseller\Arvan\ProviderError('auth', 'token rejected'));

        Provisioner::provision($order_id);

        $this->assertSame(1, $this->count_rows('notifications', "type = 'credential_failed'"),
            'a revoked token must stop rendering as «متصل»');
        $this->assertNotNull($this->db->get_var(
            'SELECT last_error FROM ' . $this->db->prefix . 'arvrs_credentials LIMIT 1'
        ));
    }

    /* -------------------------------------------------- stale-claim reclaim */

    /**
     * `provisioning` was the only non-terminal order state with no timeout of
     * its own, so a worker killed inside the window stranded a paid order
     * permanently — nothing could claim from it, ever.
     */
    public function test_an_order_abandoned_in_provisioning_is_reclaimed_and_requeued(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PROVISIONING, [
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->assertSame(1, Provisioner::reclaim_stale(20));
        $this->assertSame(StateMachine::PROVISION_FAILED, OrderService::get($order_id)['status']);
        $this->assertSame(1, $this->count_rows('jobs', "type = 'provision_order'"), 'reclaiming must also hand the work to someone');
    }

    public function test_a_fresh_provisioning_claim_is_left_alone(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PROVISIONING);

        $this->assertSame(0, Provisioner::reclaim_stale(20), 'a worker that started a minute ago is still working');
        $this->assertSame(StateMachine::PROVISIONING, OrderService::get($order_id)['status']);
    }

    /**
     * When the work actually landed and only the final transition was lost,
     * completing it is the honest repair — failing it would send a second
     * create at a resource we already own.
     */
    public function test_reclaim_completes_an_order_whose_service_already_exists(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, StateMachine::PROVISIONING, [
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        $this->seed_service($customer, $order_id);

        $this->assertSame(1, Provisioner::reclaim_stale(20));
        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status']);
        $this->assertSame(0, $this->count_rows('jobs'), 'nothing to re-run: the resource exists');
    }

    /** The admin's per-order «بازیابی» action reclaims immediately and only that order. */
    public function test_reclaim_can_be_scoped_to_one_order_with_no_wait(): void
    {
        [$mine] = $this->seed_order($this->customer(), 1200000, StateMachine::PROVISIONING);
        [$other] = $this->seed_order($this->customer(), 1200000, StateMachine::PROVISIONING);

        $this->assertSame(1, Provisioner::reclaim_stale(0, $mine));
        $this->assertSame(StateMachine::PROVISION_FAILED, OrderService::get($mine)['status']);
        $this->assertSame(StateMachine::PROVISIONING, OrderService::get($other)['status']);
    }

    /**
     * A `paid` order with no job standing behind it is recoverable work, not
     * a failure — `claim_paid()` succeeds before the provisioning job is
     * enqueued, so a fatal/OOM/timeout in that gap used to leave the order
     * `paid` forever, invisible to a sweep that only ever looked at
     * `provisioning`.
     */
    public function test_a_stale_paid_order_with_no_job_is_reclaimed(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PAID, [
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->assertSame(1, Provisioner::reclaim_stale(20));
        $this->assertSame(1, $this->count_rows('jobs', "type = 'provision_order'"));
        $this->assertSame(StateMachine::PAID, OrderService::get($order_id)['status'], 're-enqueuing is not a failure');
    }

    public function test_a_fresh_paid_order_is_left_alone(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PAID);

        $this->assertSame(0, Provisioner::reclaim_stale(20), 'an order paid moments ago has not had time to lose its job yet');
        $this->assertSame(0, $this->count_rows('jobs'));
    }

    /* --------------------------------------------- CDN/bucket name hijack */

    /**
     * A second customer's order for a domain that is already a live CDN
     * service for someone else must never adopt that resource — RealProvider's
     * create_cdn()/create_bucket() adopt by name with no ownership check of
     * their own, so this is the only place that stops it before the call.
     */
    public function test_provisioning_a_domain_already_live_for_another_customer_fails_without_adopting_it(): void
    {
        $alice = $this->customer(701);
        $bob   = $this->customer(702);
        [$alice_order] = $this->seed_order($alice, 120000, StateMachine::ACTIVE, ['product' => 'cdn', 'plan_id' => 'cdn-basic']);
        $this->seed_service($alice, $alice_order, ['product' => 'cdn', 'plan_id' => 'cdn-basic', 'remote_id' => 'shared.example.com']);

        [$bob_order] = $this->seed_order($bob, 120000, StateMachine::PAID, [
            'product' => 'cdn', 'plan_id' => 'cdn-basic',
            'config'  => '{"domain":"shared.example.com"}',
        ]);

        $result = Provisioner::provision($bob_order);

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['kind']);
        $this->assertSame(StateMachine::PROVISION_FAILED, OrderService::get($bob_order)['status']);
        $this->assertSame(1, $this->count_rows('services', "product = 'cdn'"), "bob must not adopt alice's resource");
    }

    /** The reverse must stay allowed: the SAME customer owning the domain already is never a conflict. */
    public function test_reprovisioning_the_same_customers_own_domain_is_not_a_conflict(): void
    {
        $alice = $this->customer(701);
        [$existing_order] = $this->seed_order($alice, 120000, StateMachine::ACTIVE, ['product' => 'cdn', 'plan_id' => 'cdn-basic']);
        $this->seed_service($alice, $existing_order, ['product' => 'cdn', 'plan_id' => 'cdn-basic', 'remote_id' => 'alice.example.com']);

        [$new_order] = $this->seed_order($alice, 120000, StateMachine::PAID, [
            'product' => 'cdn', 'plan_id' => 'cdn-basic',
            'config'  => '{"domain":"alice.example.com"}',
        ]);

        $result = Provisioner::provision($new_order);

        $this->assertTrue($result['ok']);
        $this->assertSame('provisioned', $result['kind'], 'the domain belongs to this same customer already — not a hijack');
    }

    /** …and once reclaimed, the order is claimable again end to end. */
    public function test_a_reclaimed_order_provisions_on_the_next_attempt(): void
    {
        [$order_id] = $this->seed_order($this->customer(), 1200000, StateMachine::PROVISIONING, [
            'updated_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]);
        Provisioner::reclaim_stale(20);

        Handlers::register();
        // The requeue carries a short delay so the reclaiming request is not
        // also the one that re-provisions; fast-forward past it.
        $this->db->query('UPDATE ' . JobRunner::table() . " SET run_at = '2000-01-01 00:00:00'");
        JobRunner::run_due();

        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status']);
        $this->assertSame(1, $this->count_rows('services', 'order_id = ' . $order_id));
    }

    /**
     * Swap in a provider that fails a given way. Uses the documented
     * `arvrs_arvan_provider` filter, so the seam under test is the shipped one.
     */
    private function use_provider_that_throws(\ArvanReseller\Arvan\ProviderError $error): void
    {
        add_filter('arvrs_arvan_provider', static function ($provider) use ($error) {
            return new class($provider, $error) implements \ArvanReseller\Arvan\ProviderInterface {
                private $inner;
                private $error;
                public function __construct($inner, $error)
                {
                    $this->inner = $inner;
                    $this->error = $error;
                }
                public function plans(string $product): array
                {
                    return $this->inner->plans($product);
                }
                public function options(string $product): array
                {
                    return $this->inner->options($product);
                }
                public function create(string $product, string $plan_id, array $config, string $idempotency_key): \ArvanReseller\Arvan\RemoteResource
                {
                    throw $this->error;
                }
                public function status(string $product, string $remote_id): \ArvanReseller\Arvan\RemoteResource
                {
                    return $this->inner->status($product, $remote_id);
                }
                public function delete(string $product, string $remote_id): bool
                {
                    return $this->inner->delete($product, $remote_id);
                }
                public function usage(string $product, array $remote_ids, string $since): array
                {
                    return $this->inner->usage($product, $remote_ids, $since);
                }
                public function test_connection(): array
                {
                    return $this->inner->test_connection();
                }
            };
        });
        \ArvanReseller\Plugin::flush_mode_cache();
    }
}
