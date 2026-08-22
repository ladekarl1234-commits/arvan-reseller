<?php
namespace ArvanReseller\Arvan;

/**
 * The single boundary between the application and ArvanCloud (ADR-0005,
 * ADR-0010). DemoProvider and RealProvider are interchangeable; business
 * logic never knows which one is active (HC-9).
 */
interface ProviderInterface
{
    /**
     * Purchasable catalog for a product. Plans carry base_cost injected from
     * the admin-maintained BaseCosts table (no official pricing API exists).
     * @return Plan[]
     * @throws ProviderError
     */
    public function plans(string $product): array;

    /**
     * Extra configuration choices for a product, e.g. regions/images for
     * cloud servers. Shape: ['regions' => [...], 'images' => [...]].
     * @throws ProviderError
     */
    public function options(string $product): array;

    /**
     * Create the remote resource for a paid order.
     *
     * $idempotency_key is NOT diagnostic decoration: implementations must send
     * it upstream AND derive the remote resource's name from it, so that an
     * attempt whose outcome is unknown can be reconciled by looking the
     * resource up instead of writing a second one. A create that times out
     * therefore raises `timeout_indeterminate` and is answered by a lookup —
     * never by a repeat POST, which is how one paid order becomes two upstream
     * invoices.
     *
     * The returned resource may legitimately be `status = 'creating'`; the
     * `poll_service` job completes it via status().
     *
     * @param array $config validated customer configuration
     * @throws ProviderError
     */
    public function create(string $product, string $plan_id, array $config, string $idempotency_key): RemoteResource;

    /**
     * Current remote state of a resource.
     * @throws ProviderError
     */
    public function status(string $product, string $remote_id): RemoteResource;

    /**
     * Delete/terminate the remote resource.
     * @throws ProviderError
     */
    public function delete(string $product, string $remote_id): bool;

    /**
     * Usage rows for a set of remote resources since a point in time.
     * Providers return closed periods only, so ingestion is idempotent.
     * @param string[] $remote_ids
     * @return UsageRow[]
     * @throws ProviderError
     */
    public function usage(string $product, array $remote_ids, string $since): array;

    /**
     * Cheap credential/connectivity test.
     * @return array{ok:bool,message:string}
     */
    public function test_connection(): array;
}
