<?php

defined('ABSPATH') || exit;

use ArvanReseller\Arvan\DemoProvider;
use ArvanReseller\Arvan\ProviderError;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Pricing\BaseCosts;

final class UsageAndRedactionTest extends Arvrs_DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseCosts::set('cloud_server', 'g1-2-2-25', 720000, 'test'); // monthly → 1000/h
    }

    public function test_demo_usage_is_deterministic_for_same_period(): void
    {
        $provider = new DemoProvider();
        $resource = $provider->create('cloud_server', 'g1-2-2-25', [], 'order:1');

        $since = gmdate('Y-m-d H:i:s', time() - 6 * 3600);
        $first  = $provider->usage('cloud_server', [$resource->remote_id], $since);
        $second = $provider->usage('cloud_server', [$resource->remote_id], $since);

        $this->assertNotEmpty($first);
        $this->assertSameSize($first, $second);
        foreach ($first as $i => $row) {
            // Identical period → identical cost: re-sync ingests nothing new
            // because (service, period) and cost match exactly.
            $this->assertSame($row->period_start, $second[$i]->period_start);
            $this->assertSame($row->cost, $second[$i]->cost);
        }
    }

    public function test_demo_usage_returns_only_closed_periods(): void
    {
        $provider = new DemoProvider();
        $resource = $provider->create('cloud_server', 'g1-2-2-25', [], 'order:2');
        $rows = $provider->usage('cloud_server', [$resource->remote_id], gmdate('Y-m-d H:i:s', time() - 3 * 3600));
        $current_hour_start = gmdate('Y-m-d H:00:00');
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual($current_hour_start, $row->period_end);
        }
    }

    public function test_same_idempotency_key_yields_same_remote_id(): void
    {
        $provider = new DemoProvider();
        $a = $provider->create('cloud_server', 'g1-2-2-25', [], 'order:42');
        $b = $provider->create('cloud_server', 'g1-2-2-25', [], 'order:42');
        $this->assertSame($a->remote_id, $b->remote_id, 'retried create must not mint a second resource');
    }

    public function test_usage_for_unknown_resource_is_skipped(): void
    {
        $provider = new DemoProvider();
        $this->assertSame([], $provider->usage('cloud_server', ['demo-unknown'], gmdate('Y-m-d H:i:s', time() - 3600)));
    }

    public function test_audit_redacts_secret_keys_recursively(): void
    {
        $clean = Audit::redact([
            'api_token' => 'SECRET-VALUE',
            'nested'    => ['Authorization' => 'Apikey abc', 'ok' => 'visible'],
            'password'  => 'hunter2',
            'note'      => 'plain',
        ]);
        $this->assertSame('[REDACTED]', $clean['api_token']);
        $this->assertSame('[REDACTED]', $clean['nested']['Authorization']);
        $this->assertSame('[REDACTED]', $clean['password']);
        $this->assertSame('visible', $clean['nested']['ok']);
        $this->assertSame('plain', $clean['note']);
    }

    public function test_provider_error_maps_to_actionable_persian(): void
    {
        $error = new ProviderError('rate_limit', 'HTTP 429');
        $this->assertStringNotContainsString('429', $error->customer_message());
        $this->assertNotSame('', $error->customer_message());
        $unknown = new ProviderError('weird', 'x');
        $this->assertNotSame('', $unknown->customer_message());
    }
}
