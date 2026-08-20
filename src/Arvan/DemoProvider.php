<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Pricing\BaseCosts;

defined('ABSPATH') || exit;

/**
 * Demo provider (HC-9, ADR-0010): simulates ONLY the external ArvanCloud
 * boundary. Catalog shape is identical to RealProvider's DTOs; created
 * resources live in a plugin option; usage generation is DETERMINISTIC per
 * (resource, period) so repeated syncs are idempotent end-to-end.
 */
final class DemoProvider implements ProviderInterface
{
    private const REGISTRY = 'arvrs_demo_resources';

    public function is_real(): bool
    {
        return false;
    }

    public function plans(string $product): array
    {
        $catalog = [
            'cloud_server' => [
                ['g1-1-1-25',  'پایه — ۱ هسته / ۱ گیگ رم',      ['CPU' => '۱ هسته', 'RAM' => '۱ گیگابایت', 'دیسک' => '۲۵ گیگ SSD', 'ترافیک' => 'نامحدود داخلی']],
                ['g1-2-2-25',  'اقتصادی — ۲ هسته / ۲ گیگ رم',   ['CPU' => '۲ هسته', 'RAM' => '۲ گیگابایت', 'دیسک' => '۲۵ گیگ SSD', 'ترافیک' => 'نامحدود داخلی']],
                ['g1-4-4-50',  'حرفه‌ای — ۴ هسته / ۴ گیگ رم',    ['CPU' => '۴ هسته', 'RAM' => '۴ گیگابایت', 'دیسک' => '۵۰ گیگ SSD', 'ترافیک' => 'نامحدود داخلی']],
                ['g1-8-8-100', 'سازمانی — ۸ هسته / ۸ گیگ رم',   ['CPU' => '۸ هسته', 'RAM' => '۸ گیگابایت', 'دیسک' => '۱۰۰ گیگ SSD', 'ترافیک' => 'نامحدود داخلی']],
            ],
            'cdn' => [
                ['cdn-basic',  'پایه — شروع رایگان',            ['ترافیک' => '۵۰ گیگ در ماه', 'گواهی SSL' => 'رایگان', 'DDoS' => 'محافظت پایه']],
                ['cdn-growth', 'رشد — سایت‌های پربازدید',        ['ترافیک' => '۵۰۰ گیگ در ماه', 'گواهی SSL' => 'رایگان', 'DDoS' => 'محافظت پیشرفته', 'WAF' => 'دارد']],
                ['cdn-pro',    'حرفه‌ای — کسب‌وکارها',           ['ترافیک' => '۳ ترابایت در ماه', 'گواهی SSL' => 'رایگان', 'DDoS' => 'محافظت کامل', 'WAF' => 'دارد', 'پشتیبانی' => 'اختصاصی']],
            ],
            'object_storage' => [
                ['os-100gb', '۱۰۰ گیگابایت',  ['فضا' => '۱۰۰ گیگابایت', 'دانلود' => '۲۰۰ گیگ در ماه', 'سازگاری' => 'S3']],
                ['os-500gb', '۵۰۰ گیگابایت',  ['فضا' => '۵۰۰ گیگابایت', 'دانلود' => '۱ ترابایت در ماه', 'سازگاری' => 'S3']],
                ['os-1tb',   '۱ ترابایت',     ['فضا' => '۱ ترابایت', 'دانلود' => '۲ ترابایت در ماه', 'سازگاری' => 'S3']],
            ],
        ];
        $plans = [];
        foreach ($catalog[$product] ?? [] as [$id, $name, $specs]) {
            $plans[] = new Plan($id, $product, $name, $specs, BaseCosts::get($product, $id));
        }
        return $plans;
    }

    public function options(string $product): array
    {
        if ($product === 'cloud_server') {
            return [
                'regions' => [
                    ['id' => 'ir-thr-simin', 'name' => 'تهران — سیمین'],
                    ['id' => 'ir-tbz-forough', 'name' => 'تبریز — فروغ'],
                ],
                'images' => [
                    ['id' => 'ubuntu-24.04', 'name' => 'Ubuntu 24.04 LTS'],
                    ['id' => 'ubuntu-22.04', 'name' => 'Ubuntu 22.04 LTS'],
                    ['id' => 'debian-12', 'name' => 'Debian 12'],
                    ['id' => 'almalinux-9', 'name' => 'AlmaLinux 9'],
                ],
            ];
        }
        if ($product === 'cdn') {
            return ['fields' => ['domain' => __('نام دامنه شما (مثل example.ir)', 'arvan-reseller')]];
        }
        if ($product === 'object_storage') {
            return ['fields' => ['bucket' => __('نام باکت (حروف کوچک انگلیسی و خط تیره)', 'arvan-reseller')]];
        }
        return [];
    }

    public function create(string $product, string $plan_id, array $config, string $idempotency_key): RemoteResource
    {
        // Simulate a short provisioning delay so the UX flow is visible.
        $remote_id = 'demo-' . $product . '-' . substr(md5($idempotency_key), 0, 10);

        $connection = [];
        if ($product === 'cloud_server') {
            $seed = crc32($remote_id);
            $connection = [
                'ip'       => '185.' . (100 + $seed % 100) . '.' . ($seed >> 8) % 256 . '.' . ($seed >> 16) % 256,
                'user'     => 'root',
                'image'    => (string) ($config['image'] ?? 'ubuntu-24.04'),
                'region'   => (string) ($config['region'] ?? 'ir-thr-simin'),
                'password_hint' => __('گذرواژه از طریق ایمیل امن ارسال شد (شبیه‌سازی دمو)', 'arvan-reseller'),
            ];
        } elseif ($product === 'cdn') {
            $connection = [
                'domain' => (string) ($config['domain'] ?? 'example.ir'),
                'ns1'    => 'ns1.arvancdn.ir',
                'ns2'    => 'ns2.arvancdn.ir',
            ];
        } elseif ($product === 'object_storage') {
            $connection = [
                'bucket'    => (string) ($config['bucket'] ?? 'my-bucket'),
                'endpoint'  => 's3.ir-thr-at1.arvanstorage.ir',
                'access_key_hint' => __('کلیدهای دسترسی در پنل سرویس نمایش داده می‌شود (شبیه‌سازی دمو)', 'arvan-reseller'),
            ];
        }

        $registry = get_option(self::REGISTRY, []);
        $registry[$remote_id] = [
            'product' => $product, 'plan_id' => $plan_id,
            'created_at' => gmdate('Y-m-d H:i:s'), 'status' => 'active',
        ];
        update_option(self::REGISTRY, $registry, false);

        return new RemoteResource($remote_id, 'active', $connection, ['demo' => true]);
    }

    public function status(string $product, string $remote_id): RemoteResource
    {
        $registry = get_option(self::REGISTRY, []);
        if (!isset($registry[$remote_id])) {
            throw new ProviderError('invalid', 'Demo resource not found: ' . $remote_id);
        }
        return new RemoteResource($remote_id, $registry[$remote_id]['status'], []);
    }

    public function delete(string $product, string $remote_id): bool
    {
        $registry = get_option(self::REGISTRY, []);
        unset($registry[$remote_id]);
        update_option(self::REGISTRY, $registry, false);
        return true;
    }

    /**
     * Deterministic hourly usage per resource: quantity/cost are a pure
     * function of (remote_id, hour), so the same closed period always yields
     * identical rows — proving ingestion dedup live (spec §5.6).
     */
    public function usage(string $product, array $remote_ids, string $since): array
    {
        $registry = get_option(self::REGISTRY, []);
        $rows  = [];
        $start = max(strtotime($since . ' UTC') ?: 0, time() - 2 * DAY_IN_SECONDS);
        $start = (int) floor($start / HOUR_IN_SECONDS) * HOUR_IN_SECONDS;
        $end_of_closed = (int) floor(time() / HOUR_IN_SECONDS) * HOUR_IN_SECONDS; // current hour is open

        foreach ($remote_ids as $rid) {
            if (!isset($registry[$rid])) {
                continue;
            }
            $monthly = BaseCosts::get($product, $registry[$rid]['plan_id']);
            $hourly  = (int) max(1, round($monthly / 720));
            for ($t = $start; $t < $end_of_closed; $t += HOUR_IN_SECONDS) {
                $jitter = (crc32($rid . $t) % 40) - 20;          // ±20% deterministic
                $cost   = (int) max(1, round($hourly * (100 + $jitter) / 100));
                $rows[] = new UsageRow(
                    $rid,
                    gmdate('Y-m-d H:i:s', $t),
                    gmdate('Y-m-d H:i:s', $t + HOUR_IN_SECONDS),
                    round($cost / max(1, $hourly), 4),
                    'hour',
                    $cost
                );
            }
        }
        return $rows;
    }

    public function test_connection(): array
    {
        return ['ok' => true, 'message' => __('حالت دمو: اتصال شبیه‌سازی‌شده برقرار است.', 'arvan-reseller')];
    }
}
