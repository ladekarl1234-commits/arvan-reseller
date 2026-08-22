<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Pricing\BaseCosts;

defined('ABSPATH') || exit;

/**
 * Real ArvanCloud provider. Every endpoint below is officially documented —
 * ECC swagger (arvancloud/ecc-go-client), CDN 4.0 Redoc, Object Storage
 * OpenAPI (storage-1.0.0.yaml). See docs/API_INTEGRATION.md for the full
 * verified endpoint reference and the explicit NOT-AVAILABLE list (no
 * billing/usage API → recurring revenue comes from Billing\Renewals, not from
 * an upstream meter; no pricing API → BaseCosts).
 *
 * Creates are made reconcilable rather than merely "hopefully single-shot":
 * every remote resource is named deterministically from the order's
 * idempotency key, we look it up before creating, and after an indeterminate
 * outcome we look it up again instead of creating a second one. That holds
 * even if ArvanCloud ignores the Idempotency-Key header entirely — which we
 * cannot verify, so we do not depend on it.
 */
final class RealProvider implements ProviderInterface
{
    private const ECC     = '/ecc/v1';
    private const CDN     = '/cdn/4.0';
    private const STORAGE = 'https://storage.arvanapis.ir/v1';

    /** Object Storage regions we can provision into (options + create agree). */
    private const STORAGE_REGIONS = ['ir-central1', 'ir-northwest1'];

    /** @var ArvanClient|null */
    private $client;

    /** @var int */
    private $credential_id;

    /** @param array{id:int,token:string}|null $credential */
    public function __construct(?array $credential)
    {
        $this->client        = $credential ? new ArvanClient($credential['token']) : null;
        $this->credential_id = $credential['id'] ?? 0;
    }

    /** @throws ProviderError */
    private function client(): ArvanClient
    {
        if (!$this->client) {
            throw new ProviderError('auth', 'No enabled ArvanCloud credential is configured.');
        }
        return $this->client;
    }

    /**
     * The remote name we give every resource we create, derived only from the
     * order's idempotency key. This is what makes a create reconcilable: after
     * an unknown outcome the resource can be found again by name.
     * `order:41` → `arvrs-order-41`.
     */
    public static function remote_name(string $idempotency_key): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($idempotency_key)), '-');
        return substr('arvrs-' . ($slug !== '' ? $slug : 'unkeyed'), 0, 32);
    }

    private function default_region(): string
    {
        $regions = $this->client()->request('GET', self::ECC . '/regions');
        foreach (($regions['data'] ?? []) as $region) {
            if (!empty($region['code'])) {
                return (string) $region['code'];
            }
        }
        throw new ProviderError('unavailable', 'No ECC region available for this account.');
    }

    public function plans(string $product): array
    {
        if ($product === 'cloud_server') {
            $region = $this->default_region();
            $sizes  = $this->client()->request('GET', self::ECC . '/regions/' . rawurlencode($region) . '/sizes');
            $plans  = [];
            foreach (($sizes['data'] ?? []) as $size) {
                $id = (string) ($size['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                // Unpriced flavors are RETURNED, not dropped: upstream flavor
                // ids never match the seeded demo ids, so silently filtering
                // them is what makes real-mode cloud servers unsellable with
                // no visible cause. The flag lets admin/storefront say so.
                $cost = BaseCosts::get($product, $id);
                $plans[] = new Plan($id, $product, (string) ($size['name'] ?? $id), [
                    'CPU'  => (string) ($size['cpu_count'] ?? '?') . ' هسته',
                    'RAM'  => (string) ($size['memory'] ?? '?') . ' مگابایت',
                    'دیسک' => (string) ($size['disk'] ?? '?') . ' گیگابایت',
                ], $cost, ['region' => $region, 'unpriced' => $cost <= 0]);
            }
            return $plans;
        }

        if ($product === 'cdn') {
            // Plan levels 1..3 are documented (PUT /domains/{domain}/plan).
            $catalog = [
                ['cdn-basic',  '1', 'پایه'],
                ['cdn-growth', '2', 'رشد'],
                ['cdn-pro',    '3', 'حرفه‌ای'],
            ];
            $plans = [];
            foreach ($catalog as [$id, $level, $name]) {
                $cost    = BaseCosts::get($product, $id);
                $plans[] = new Plan($id, $product, $name, ['سطح پلن' => $level], $cost, ['plan_level' => $level, 'unpriced' => $cost <= 0]);
            }
            return $plans;
        }

        if ($product === 'object_storage') {
            // Storage billing is usage-based upstream; the reseller sells
            // admin-defined packages (BaseCosts) and provisions the bucket.
            $plans = [];
            foreach ([['os-100gb', '۱۰۰ گیگابایت'], ['os-500gb', '۵۰۰ گیگابایت'], ['os-1tb', '۱ ترابایت']] as [$id, $name]) {
                $cost    = BaseCosts::get($product, $id);
                $plans[] = new Plan($id, $product, $name, ['سازگاری' => 'S3'], $cost, ['unpriced' => $cost <= 0]);
            }
            return $plans;
        }
        return [];
    }

    /**
     * Upstream plan ids that carry no base cost and therefore cannot be sold.
     * The admin imports them into base_costs in one click instead of hand-
     * transcribing every ArvanCloud flavor id.
     * @return array<int,array{plan_id:string,name:string,meta:array}>
     * @throws ProviderError
     */
    public function importable_plans(string $product): array
    {
        $out = [];
        foreach ($this->plans($product) as $plan) {
            if ($plan->base_cost > 0) {
                continue;
            }
            $out[] = ['plan_id' => $plan->id, 'name' => $plan->name, 'meta' => $plan->meta];
        }
        return $out;
    }

    public function options(string $product): array
    {
        if ($product === 'cloud_server') {
            $region  = $this->default_region();
            $images  = $this->client()->request('GET', self::ECC . '/regions/' . rawurlencode($region) . '/images?type=distributions');
            $regions = $this->client()->request('GET', self::ECC . '/regions');
            $out     = ['regions' => [], 'images' => []];
            foreach (($regions['data'] ?? []) as $r) {
                $out['regions'][] = ['id' => (string) $r['code'], 'name' => (string) ($r['city'] ?? $r['code'])];
            }
            foreach (($images['data'] ?? []) as $group) {
                foreach ((array) ($group['images'] ?? [$group]) as $image) {
                    if (!empty($image['id'])) {
                        $out['images'][] = ['id' => (string) $image['id'], 'name' => trim((string) ($image['distribution_name'] ?? '') . ' ' . (string) ($image['name'] ?? ''))];
                    }
                }
            }
            return $out;
        }
        if ($product === 'cdn') {
            return ['fields' => ['domain' => __('نام دامنه شما', 'arvan-reseller')]];
        }
        if ($product === 'object_storage') {
            return ['fields' => ['bucket' => __('نام باکت', 'arvan-reseller')], 'regions' => [
                ['id' => 'ir-central1', 'name' => 'ایران مرکزی (سیمین)'],
                ['id' => 'ir-northwest1', 'name' => 'شمال‌غرب (شهریار)'],
            ]];
        }
        return [];
    }

    public function create(string $product, string $plan_id, array $config, string $idempotency_key): RemoteResource
    {
        // Validate BEFORE the write: flavor ids are region-scoped upstream, so
        // an unchecked region/flavor pair is a guaranteed 422 *after* the
        // customer has already paid.
        $this->validate_config($product, $plan_id, $config);

        if ($product === 'cloud_server') {
            return $this->create_server($plan_id, $config, $idempotency_key);
        }
        if ($product === 'cdn') {
            return $this->create_cdn($plan_id, $config, $idempotency_key);
        }
        if ($product === 'object_storage') {
            return $this->create_bucket($config, $idempotency_key);
        }
        throw new ProviderError('invalid', 'Unknown product: ' . $product);
    }

    /**
     * Reject customer configuration that the live catalog does not offer.
     * Membership is only enforced against a NON-EMPTY catalog list: a catalog
     * outage must not turn every queued provisioning job into a permanent
     * `invalid` failure.
     * @throws ProviderError
     */
    private function validate_config(string $product, string $plan_id, array $config): void
    {
        $plans = Catalog::plans($product);
        $plan  = null;
        foreach ($plans as $candidate) {
            if ((string) $candidate['id'] === $plan_id) {
                $plan = $candidate;
                break;
            }
        }
        if ($plans && $plan === null) {
            throw new ProviderError('invalid', 'Plan ' . $plan_id . ' is not in the ' . $product . ' catalog');
        }

        $options = Catalog::options($product);
        $region  = (string) ($config['region'] ?? '');

        if ($product === 'object_storage') {
            if ($region !== '' && !in_array($region, self::STORAGE_REGIONS, true)) {
                throw new ProviderError('invalid', 'Unknown object storage region: ' . $region);
            }
            return;
        }
        if ($product !== 'cloud_server') {
            return;
        }

        if ($region !== '' && !empty($options['regions']) && !self::has_id($options['regions'], $region)) {
            throw new ProviderError('invalid', 'Region ' . $region . ' is not offered for cloud servers');
        }
        $image = (string) ($config['image'] ?? '');
        if (!empty($options['images']) && !self::has_id($options['images'], $image)) {
            throw new ProviderError('invalid', 'Image ' . $image . ' is not offered for cloud servers');
        }

        // The flavor list was fetched from ONE region. If the customer picked a
        // different one, the id must be confirmed against that region or the
        // create is a 422 waiting to happen.
        $plan_region = $plan ? (string) ($plan['meta']['region'] ?? '') : '';
        if ($region !== '' && $plan_region !== '' && $region !== $plan_region) {
            $sizes = $this->client()->request('GET', self::ECC . '/regions/' . rawurlencode($region) . '/sizes');
            foreach (($sizes['data'] ?? []) as $size) {
                if ((string) ($size['id'] ?? '') === $plan_id) {
                    return;
                }
            }
            throw new ProviderError('invalid', 'Flavor ' . $plan_id . ' is not available in region ' . $region);
        }
    }

    /** @param array<int,array{id:string}> $list */
    private static function has_id(array $list, string $id): bool
    {
        foreach ($list as $entry) {
            if ((string) ($entry['id'] ?? '') === $id) {
                return true;
            }
        }
        return false;
    }

    private function create_server(string $flavor_id, array $config, string $idempotency_key): RemoteResource
    {
        $region = !empty($config['region']) ? (string) $config['region'] : $this->default_region();
        $base   = self::ECC . '/regions/' . rawurlencode($region);
        $name   = self::remote_name($idempotency_key);

        // List before create. If an earlier attempt already produced this
        // server — including one whose response we never saw — it carries this
        // exact name, and it is ours.
        $existing = $this->find_server($base, $region, $name, false);
        if ($existing !== null) {
            return $existing;
        }

        // network_id is required by the documented create body → pick the
        // account's first network in the region.
        $networks   = $this->client()->request('GET', $base . '/networks');
        $network_id = '';
        foreach (($networks['data'] ?? []) as $network) {
            if (!empty($network['id'])) {
                $network_id = (string) $network['id'];
                break;
            }
        }
        if ($network_id === '') {
            throw new ProviderError('invalid', 'No network available in region ' . $region);
        }

        // Prefer the account's default security group; otherwise the first one.
        $security_groups = [];
        $securities      = $this->client()->request('GET', $base . '/securities');
        foreach (($securities['data'] ?? []) as $sg) {
            $sg_name = (string) ($sg['name'] ?? ($sg['id'] ?? ''));
            if ($sg_name === '') {
                continue;
            }
            if (!empty($sg['default'])) {
                $security_groups = [['name' => $sg_name]];
                break;
            }
            if (!$security_groups) {
                $security_groups = [['name' => $sg_name]];
            }
        }

        $body = [
            'name'       => $name,
            'network_id' => $network_id,
            'flavor_id'  => $flavor_id,
            'image_id'   => (string) ($config['image'] ?? ''),
            'ssh_key'    => false,
            'count'      => 1,
        ];
        if ($security_groups) {
            $body['security_groups'] = $security_groups;
        }

        $label = substr(sanitize_text_field((string) ($config['name'] ?? '')), 0, 50);

        try {
            $created = $this->client()->request('POST', $base . '/servers', $body, ['idempotency_key' => $idempotency_key]);
        } catch (ProviderError $e) {
            // Unknown outcome or "already exists": look, do not guess. A second
            // POST here is how one paid order becomes two upstream invoices.
            if (in_array($e->kind, ['timeout_indeterminate', 'conflict', 'invalid'], true)) {
                $found = $this->find_server($base, $region, $name, true);
                if ($found !== null) {
                    return $this->with_label($found, $label);
                }
            }
            throw $e;
        }

        $server = $created['data'] ?? [];
        $id     = (string) ($server['id'] ?? '');
        if ($id === '') {
            $found = $this->find_server($base, $region, $name, true);
            if ($found !== null) {
                return $this->with_label($found, $label);
            }
            throw new ProviderError('unknown', 'Server create returned no ID', $this->client()->last_correlation_id());
        }

        // No polling: the payment callback must not hold a PHP worker for 15s.
        // The `poll_service` job completes the picture via status().
        return $this->with_label($this->server_resource($region, $id, $server), $label);
    }

    private function with_label(RemoteResource $resource, string $label): RemoteResource
    {
        if ($label !== '') {
            $resource->connection['label'] = $label;
        }
        return $resource;
    }

    /**
     * Find our server by its deterministic name.
     * @param bool $tolerate_errors true after a failed create — a listing error
     *        then means "cannot prove it exists", and the caller rethrows the
     *        original indeterminate error rather than creating a duplicate.
     */
    private function find_server(string $base, string $region, string $name, bool $tolerate_errors): ?RemoteResource
    {
        try {
            $list = $this->client()->request('GET', $base . '/servers');
        } catch (ProviderError $e) {
            if ($tolerate_errors) {
                return null;
            }
            throw $e;
        }
        foreach (($list['data'] ?? []) as $server) {
            if ((string) ($server['name'] ?? '') === $name && !empty($server['id'])) {
                return $this->server_resource($region, (string) $server['id'], $server);
            }
        }
        return null;
    }

    /** Normalize an ECC server payload into the DTO both create and status return. */
    private function server_resource(string $region, string $id, array $server): RemoteResource
    {
        $ip     = self::public_address($server);
        $status = strtolower((string) ($server['status'] ?? ''));
        $ready  = $ip !== '' && ($status === '' || $status === 'active');

        $connection = array_filter([
            'ip'     => $ip,
            'user'   => 'root',
            'region' => $region,
        ]);
        $password = (string) ($server['password'] ?? '');
        // Arvan mails the root password to the ACCOUNT owner (the reseller), so
        // when the create response carries none, say so instead of handing the
        // customer a card with an IP and no way in.
        $connection['password_hint'] = $password !== ''
            ? $password
            : __('گذرواژه ریشه از سوی آروان برای مالک حساب ارسال می‌شود؛ پشتیبانی آن را برای شما ارسال یا بازنشانی می‌کند.', 'arvan-reseller');

        return new RemoteResource(
            $region . '/' . $id,
            $ready ? 'active' : 'creating',
            $connection,
            ['region' => $region],
            $this->client()->last_correlation_id()
        );
    }

    private static function public_address(array $server): string
    {
        $fallback = '';
        foreach (($server['addresses'] ?? []) as $addresses) {
            foreach ((array) $addresses as $address) {
                if (empty($address['addr'])) {
                    continue;
                }
                if (!empty($address['is_public'])) {
                    return (string) $address['addr'];
                }
                if ($fallback === '') {
                    $fallback = (string) $address['addr'];
                }
            }
        }
        return $fallback;
    }

    private function create_cdn(string $plan_id, array $config, string $idempotency_key): RemoteResource
    {
        $domain = (string) ($config['domain'] ?? '');
        if ($domain === '') {
            throw new ProviderError('invalid', 'CDN requires a domain');
        }

        // The domain IS the natural idempotency key for CDN: if it is already
        // on the account, it is the one we created for this order.
        $existing = $this->find_cdn($domain, true);
        if ($existing !== null) {
            $this->apply_cdn_plan($domain, $plan_id);
            return $this->cdn_resource($domain);
        }

        try {
            $this->client()->request('POST', self::CDN . '/domains/dns-service', [
                'domain'      => $domain,
                'domain_type' => 'full',
            ], ['idempotency_key' => $idempotency_key]);
        } catch (ProviderError $e) {
            // 409 is literally "already created"; an indeterminate timeout may
            // be too. Either way the answer is a lookup, never a second POST.
            if (in_array($e->kind, ['conflict', 'timeout_indeterminate', 'invalid'], true)) {
                $found = $this->find_cdn($domain, true);
                if ($found !== null) {
                    $this->apply_cdn_plan($domain, $plan_id);
                    return $this->cdn_resource($domain);
                }
            }
            throw $e;
        }

        $this->apply_cdn_plan($domain, $plan_id);
        return $this->cdn_resource($domain);
    }

    private function apply_cdn_plan(string $domain, string $plan_id): void
    {
        $levels = ['cdn-basic' => '1', 'cdn-growth' => '2', 'cdn-pro' => '3'];
        if (!isset($levels[$plan_id])) {
            return;
        }
        try {
            $this->client()->request('PUT', self::CDN . '/domains/' . rawurlencode($domain) . '/plan', ['plan_level' => $levels[$plan_id]]);
        } catch (ProviderError $e) {
            // Plan upgrade failing must not orphan the created domain; the
            // admin can retry the plan change from Arvan panel/API later.
        }
    }

    /** @return RemoteResource|null null when the domain is not on the account */
    private function find_cdn(string $domain, bool $tolerate_errors): ?RemoteResource
    {
        try {
            $details = $this->client()->request('GET', self::CDN . '/domains/' . rawurlencode($domain));
        } catch (ProviderError $e) {
            if ($e->kind === 'invalid') {
                return null; // 404 — genuinely not created yet
            }
            if ($tolerate_errors) {
                return null;
            }
            throw $e;
        }
        return empty($details['data']) ? null : new RemoteResource($domain, 'active', ['domain' => $domain], [], $this->client()->last_correlation_id());
    }

    private function cdn_resource(string $domain): RemoteResource
    {
        $ns = [];
        try {
            $check = $this->client()->request('GET', self::CDN . '/domains/' . rawurlencode($domain) . '/ns-keys/check');
            $ns    = (array) ($check['data']['ns_keys'] ?? []);
        } catch (ProviderError $e) {
            // NS info is cosmetic here.
        }
        return new RemoteResource($domain, 'active', array_filter([
            'domain' => $domain,
            'ns1'    => (string) ($ns[0] ?? ''),
            'ns2'    => (string) ($ns[1] ?? ''),
        ]), [], $this->client()->last_correlation_id());
    }

    private function create_bucket(array $config, string $idempotency_key): RemoteResource
    {
        $bucket = (string) ($config['bucket'] ?? '');
        if ($bucket === '') {
            throw new ProviderError('invalid', 'Object storage requires a bucket name');
        }
        $region = in_array($config['region'] ?? '', self::STORAGE_REGIONS, true)
            ? (string) $config['region']
            : self::STORAGE_REGIONS[0];

        // The bucket name is the natural key — buckets are globally unique.
        if ($this->bucket_exists($bucket, true)) {
            return $this->bucket_resource($bucket, $region);
        }

        try {
            $this->client()->request('POST', self::STORAGE . '/buckets', [
                'name'   => $bucket,
                'region' => $region,
            ], ['idempotency_key' => $idempotency_key]);
        } catch (ProviderError $e) {
            if (in_array($e->kind, ['conflict', 'timeout_indeterminate', 'invalid'], true) && $this->bucket_exists($bucket, true)) {
                return $this->bucket_resource($bucket, $region);
            }
            throw $e;
        }

        return $this->bucket_resource($bucket, $region);
    }

    private function bucket_exists(string $bucket, bool $tolerate_errors): bool
    {
        try {
            $details = $this->client()->request('GET', self::STORAGE . '/buckets/' . rawurlencode($bucket));
        } catch (ProviderError $e) {
            if ($e->kind === 'invalid') {
                return false; // 404 — not created yet
            }
            if ($tolerate_errors) {
                return false;
            }
            throw $e;
        }
        return !empty($details);
    }

    private function bucket_resource(string $bucket, string $region): RemoteResource
    {
        return new RemoteResource($bucket, 'active', [
            'bucket'   => $bucket,
            'region'   => $region,
            'endpoint' => $region === 'ir-central1' ? 's3.ir-thr-at1.arvanstorage.ir' : 's3.ir-tbz-sh1.arvanstorage.ir',
            'access_key_hint' => __('کلیدهای دسترسی S3 از پنل فضای ابری آروان دریافت می‌شود (endpoint مدیریتی برای صدور کلید مستند نیست).', 'arvan-reseller'),
        ], [], $this->client()->last_correlation_id());
    }

    public function status(string $product, string $remote_id): RemoteResource
    {
        if ($product === 'cloud_server') {
            [$region, $id] = array_pad(explode('/', $remote_id, 2), 2, '');
            $details = $this->client()->request('GET', self::ECC . '/regions/' . rawurlencode($region) . '/servers/' . rawurlencode($id));
            // Returns the connection info too, so the poll job can fill in the
            // address that create() deliberately no longer waits for.
            return $this->server_resource($region, $id, (array) ($details['data'] ?? []));
        }
        if ($product === 'cdn') {
            $details = $this->client()->request('GET', self::CDN . '/domains/' . rawurlencode($remote_id));
            return new RemoteResource($remote_id, !empty($details['data']) ? 'active' : 'unknown', ['domain' => $remote_id], [], $this->client()->last_correlation_id());
        }
        if ($product === 'object_storage') {
            $details = $this->client()->request('GET', self::STORAGE . '/buckets/' . rawurlencode($remote_id));
            return new RemoteResource($remote_id, !empty($details) ? 'active' : 'unknown', ['bucket' => $remote_id], [], $this->client()->last_correlation_id());
        }
        throw new ProviderError('invalid', 'Unknown product');
    }

    public function delete(string $product, string $remote_id): bool
    {
        if ($product === 'cloud_server') {
            [$region, $id] = array_pad(explode('/', $remote_id, 2), 2, '');
            $this->client()->request('DELETE', self::ECC . '/regions/' . rawurlencode($region) . '/servers/' . rawurlencode($id));
            return true;
        }
        if ($product === 'cdn') {
            $this->client()->request('DELETE', self::CDN . '/domains/' . rawurlencode($remote_id));
            return true;
        }
        if ($product === 'object_storage') {
            $this->client()->request('DELETE', self::STORAGE . '/buckets/' . rawurlencode($remote_id));
            return true;
        }
        return false;
    }

    /**
     * ArvanCloud publishes NO public billing or usage API for ECC/CDN/Storage
     * (verified — docs/API_INTEGRATION.md §5). This is not a stub waiting for
     * an endpoint: there is nothing upstream to call, so real-mode usage rows
     * are always zero, permanently and by design.
     *
     * Recurring revenue therefore comes from `ArvanReseller\Billing\Renewals`,
     * which charges the sold package per term from data the plugin owns. Any
     * UI, doc or health check implying an upstream meter exists is wrong.
     */
    public function usage(string $product, array $remote_ids, string $since): array
    {
        return [];
    }

    public function test_connection(): array
    {
        try {
            $this->client()->request('GET', self::ECC . '/regions');
            return ['ok' => true, 'message' => __('اتصال به ArvanCloud برقرار است.', 'arvan-reseller')];
        } catch (ProviderError $e) {
            $map = [
                'auth'        => __('توکن API معتبر نیست یا دسترسی ندارد.', 'arvan-reseller'),
                'timeout'     => __('پاسخ ArvanCloud دیر شد. دوباره تلاش کنید.', 'arvan-reseller'),
                'unavailable' => __('سرویس ArvanCloud در دسترس نیست.', 'arvan-reseller'),
                'billing'     => __('حساب ArvanCloud اعتبار کافی ندارد.', 'arvan-reseller'),
            ];
            return ['ok' => false, 'message' => ($map[$e->kind] ?? $e->getMessage()) . ' [' . $e->correlation_id . ']'];
        }
    }
}
