<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Pricing\BaseCosts;

defined('ABSPATH') || exit;

/**
 * Real ArvanCloud provider. Every endpoint below is officially documented —
 * ECC swagger (arvancloud/ecc-go-client), CDN 4.0 Redoc, Object Storage
 * OpenAPI (storage-1.0.0.yaml). See docs/API_INTEGRATION.md for the full
 * verified endpoint reference and the explicit NOT-AVAILABLE list (no
 * billing/usage API → the Usage engine treats real-mode usage as a
 * documented limitation; no pricing API → BaseCosts).
 */
final class RealProvider implements ProviderInterface
{
    private const ECC     = '/ecc/v1';
    private const CDN     = '/cdn/4.0';
    private const STORAGE = 'https://storage.arvanapis.ir/v1';

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

    public function is_real(): bool
    {
        return true;
    }

    /** @throws ProviderError */
    private function client(): ArvanClient
    {
        if (!$this->client) {
            throw new ProviderError('auth', 'No enabled ArvanCloud credential is configured.');
        }
        return $this->client;
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
                $plans[] = new Plan($id, $product, (string) ($size['name'] ?? $id), [
                    'CPU'  => (string) ($size['cpu_count'] ?? '?') . ' هسته',
                    'RAM'  => (string) ($size['memory'] ?? '?') . ' مگابایت',
                    'دیسک' => (string) ($size['disk'] ?? '?') . ' گیگابایت',
                ], BaseCosts::get($product, $id), ['region' => $region]);
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
                $plans[] = new Plan($id, $product, $name, ['سطح پلن' => $level], BaseCosts::get($product, $id), ['plan_level' => $level]);
            }
            return $plans;
        }

        if ($product === 'object_storage') {
            // Storage billing is usage-based upstream; the reseller sells
            // admin-defined packages (BaseCosts) and provisions the bucket.
            $plans = [];
            foreach ([['os-100gb', '۱۰۰ گیگابایت'], ['os-500gb', '۵۰۰ گیگابایت'], ['os-1tb', '۱ ترابایت']] as [$id, $name]) {
                $plans[] = new Plan($id, $product, $name, ['سازگاری' => 'S3'], BaseCosts::get($product, $id));
            }
            return $plans;
        }
        return [];
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
        if ($product === 'cloud_server') {
            return $this->create_server($plan_id, $config);
        }
        if ($product === 'cdn') {
            return $this->create_cdn($plan_id, $config);
        }
        if ($product === 'object_storage') {
            return $this->create_bucket($config);
        }
        throw new ProviderError('invalid', 'Unknown product: ' . $product);
    }

    private function create_server(string $flavor_id, array $config): RemoteResource
    {
        $region = !empty($config['region']) ? (string) $config['region'] : $this->default_region();
        $base   = self::ECC . '/regions/' . rawurlencode($region);

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

        $security_groups = [];
        $securities      = $this->client()->request('GET', $base . '/securities');
        foreach (($securities['data'] ?? []) as $sg) {
            if (!empty($sg['id']) && (!empty($sg['default']) || empty($security_groups))) {
                $security_groups = [['name' => (string) $sg['id']]];
            }
        }

        $body = [
            'name'       => ($config['name'] ?? '') !== '' ? (string) $config['name'] : 'srv-' . wp_generate_password(8, false, false),
            'network_id' => $network_id,
            'flavor_id'  => $flavor_id,
            'image_id'   => (string) ($config['image'] ?? ''),
            'ssh_key'    => false,
            'count'      => 1,
        ];
        if ($security_groups) {
            $body['security_groups'] = $security_groups;
        }

        $created = $this->client()->request('POST', $base . '/servers', $body);
        $server  = $created['data'] ?? [];
        $id      = (string) ($server['id'] ?? '');
        if ($id === '') {
            throw new ProviderError('unknown', 'Server create returned no ID');
        }

        // Brief synchronous poll for the address; otherwise hand back
        // "creating" and let status() complete the picture later.
        $ip = '';
        for ($i = 0; $i < 5 && $ip === ''; $i++) {
            sleep(3);
            try {
                $details = $this->client()->request('GET', $base . '/servers/' . rawurlencode($id));
                foreach (($details['data']['addresses'] ?? []) as $addresses) {
                    foreach ((array) $addresses as $address) {
                        if (!empty($address['addr']) && empty($address['is_public']) === false) {
                            $ip = (string) $address['addr'];
                            break 2;
                        }
                        if (!empty($address['addr']) && $ip === '') {
                            $ip = (string) $address['addr'];
                        }
                    }
                }
            } catch (ProviderError $e) {
                break; // details not ready — not fatal
            }
        }

        return new RemoteResource($region . '/' . $id, $ip ? 'active' : 'creating', array_filter([
            'ip'     => $ip,
            'user'   => 'root',
            'region' => $region,
            'password_hint' => (string) ($server['password'] ?? ''),
        ]), ['region' => $region]);
    }

    private function create_cdn(string $plan_id, array $config): RemoteResource
    {
        $domain = (string) ($config['domain'] ?? '');
        $this->client()->request('POST', self::CDN . '/domains/dns-service', [
            'domain'      => $domain,
            'domain_type' => 'full',
        ]);
        $levels = ['cdn-basic' => '1', 'cdn-growth' => '2', 'cdn-pro' => '3'];
        if (isset($levels[$plan_id])) {
            try {
                $this->client()->request('PUT', self::CDN . '/domains/' . rawurlencode($domain) . '/plan', ['plan_level' => $levels[$plan_id]]);
            } catch (ProviderError $e) {
                // Plan upgrade failing must not orphan the created domain; the
                // admin can retry the plan change from Arvan panel/API later.
            }
        }
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
        ]));
    }

    private function create_bucket(array $config): RemoteResource
    {
        $bucket = (string) ($config['bucket'] ?? '');
        $region = in_array($config['region'] ?? '', ['ir-central1', 'ir-northwest1'], true) ? $config['region'] : 'ir-central1';
        $this->client()->request('POST', self::STORAGE . '/buckets', [
            'name'   => $bucket,
            'region' => $region,
        ]);
        return new RemoteResource($bucket, 'active', [
            'bucket'   => $bucket,
            'region'   => $region,
            'endpoint' => $region === 'ir-central1' ? 's3.ir-thr-at1.arvanstorage.ir' : 's3.ir-tbz-sh1.arvanstorage.ir',
            'access_key_hint' => __('کلیدهای دسترسی S3 از پنل فضای ابری آروان دریافت می‌شود (endpoint مدیریتی برای صدور کلید مستند نیست).', 'arvan-reseller'),
        ]);
    }

    public function status(string $product, string $remote_id): RemoteResource
    {
        if ($product === 'cloud_server') {
            [$region, $id] = array_pad(explode('/', $remote_id, 2), 2, '');
            $details = $this->client()->request('GET', self::ECC . '/regions/' . rawurlencode($region) . '/servers/' . rawurlencode($id));
            $status  = (string) ($details['data']['status'] ?? 'unknown');
            return new RemoteResource($remote_id, $status === 'ACTIVE' ? 'active' : strtolower($status));
        }
        if ($product === 'cdn') {
            $details = $this->client()->request('GET', self::CDN . '/domains/' . rawurlencode($remote_id));
            return new RemoteResource($remote_id, !empty($details['data']) ? 'active' : 'unknown');
        }
        if ($product === 'object_storage') {
            $details = $this->client()->request('GET', self::STORAGE . '/buckets/' . rawurlencode($remote_id));
            return new RemoteResource($remote_id, !empty($details) ? 'active' : 'unknown');
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
     * No public billing/usage API exists for ECC/CDN (verified — see
     * docs/API_INTEGRATION.md §5). Real-mode usage therefore returns no rows;
     * the reseller bills the fixed monthly package price instead. When Arvan
     * publishes a usage API this method is the single place to implement it.
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
            ];
            return ['ok' => false, 'message' => ($map[$e->kind] ?? $e->getMessage()) . ' [' . $e->correlation_id . ']'];
        }
    }
}
