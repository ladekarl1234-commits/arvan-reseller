<?php
namespace ArvanReseller\Support;

defined('ABSPATH') || exit;

final class Helpers
{
    /** Object-cache group for the atomic rate-limit counters. */
    private const RL_GROUP = 'arvrs_rl';

    /** Jalali month names, index 0 = فروردین. */
    private const JALALI_MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /** Render a template from templates/ with extracted vars, returning HTML. */
    public static function view(string $template, array $vars = []): string
    {
        $file = ARVRS_DIR . 'templates/' . $template . '.php';
        if (!is_file($file)) {
            return '';
        }
        ob_start();
        extract($vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract
        include $file;
        return (string) ob_get_clean();
    }

    /** Format toman with Persian digits and thousands separators: ۱۲٬۰۰۰٬۰۰۰ تومان */
    public static function money(int $amount): string
    {
        $formatted = number_format($amount);
        return self::fa_digits($formatted) . ' ' . __('تومان', 'arvan-reseller');
    }

    public static function fa_digits(string $s): string
    {
        return strtr($s, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ',' => '٬',
        ]);
    }

    /** Safe status badge HTML shared by admin/front templates. */
    public static function status_tag(string $status): string
    {
        $map = [
            'pending_payment'    => ['warning', __('در انتظار پرداخت', 'arvan-reseller')],
            'payment_processing' => ['warning', __('در حال پرداخت', 'arvan-reseller')],
            'paid'               => ['info', __('پرداخت‌شده', 'arvan-reseller')],
            'provisioning'       => ['info', __('در حال راه‌اندازی', 'arvan-reseller')],
            'active'             => ['success', __('فعال', 'arvan-reseller')],
            'provision_failed'   => ['danger', __('خطا در راه‌اندازی', 'arvan-reseller')],
            'cancelled'          => ['default', __('لغوشده', 'arvan-reseller')],
            'refunded'           => ['default', __('بازپرداخت‌شده', 'arvan-reseller')],
            'at_risk'            => ['warning', __('در معرض تعلیق', 'arvan-reseller')],
            'suspended'          => ['danger', __('معلق', 'arvan-reseller')],
            'healthy'            => ['success', __('سالم', 'arvan-reseller')],
            'warning'            => ['warning', __('هشدار', 'arvan-reseller')],
            'critical'           => ['danger', __('بحرانی', 'arvan-reseller')],
            'grace'              => ['danger', __('مهلت', 'arvan-reseller')],
            'restricted'         => ['danger', __('محدودشده', 'arvan-reseller')],
            'pending'            => ['warning', __('در صف', 'arvan-reseller')],
            'running'            => ['info', __('در حال اجرا', 'arvan-reseller')],
            'done'               => ['success', __('انجام‌شده', 'arvan-reseller')],
            'dead'               => ['danger', __('متوقف', 'arvan-reseller')],
            'blocked'            => ['danger', __('مسدود', 'arvan-reseller')],
        ];
        [$kind, $label] = $map[$status] ?? ['default', $status];
        return '<span class="arvrs-tag arvrs-tag-' . esc_attr($kind) . '">' . esc_html($label) . '</span>';
    }

    /** Best-effort client IP for audit rows (never used for auth decisions). */
    public static function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 45);
    }

    public static function now(): string
    {
        return current_time('mysql', true);
    }

    /** Short trace id shared by an order, its audit rows and its upstream calls. */
    public static function correlation_id(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 8);
    }

    /**
     * Fixed-window rate limiter (SEC-11).
     *
     * A persistent object cache gives a genuinely atomic counter: `wp_cache_add`
     * is a compare-and-create and `wp_cache_incr` maps onto memcached/redis
     * INCR, so a concurrent burst of N requests counts as N. That is the whole
     * point — the previous get-then-set advanced the counter by 1 no matter how
     * many requests raced through the window.
     *
     * ponytail: without a persistent object cache WordPress's cache is
     * per-request, so we fall back to the transient counter and the intra-server
     * race returns. Documented in SECURITY.md next to the existing "per-server,
     * not global" note; the upgrade path is installing an object cache.
     *
     * @return bool true when the call is allowed
     */
    public static function rate_limit(string $bucket, int $max, int $window_seconds): bool
    {
        $key = 'arvrs_rl_' . md5($bucket); // md5 = cache-key derivation only, not secret storage

        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::RL_GROUP, $window_seconds);
            $hits = wp_cache_incr($key, 1, self::RL_GROUP);
            if ($hits !== false) {
                return (int) $hits <= $max;
            }
            // incr lost the key (evicted mid-window) — fall through rather than
            // fail open on a limiter.
        }

        $hits = (int) get_transient($key);
        if ($hits >= $max) {
            return false;
        }
        set_transient($key, $hits + 1, $window_seconds);
        return true;
    }

    /**
     * Gregorian (UTC) → Jalali, formatted for a Persian reader.
     *
     * Pure PHP: no `intl`, no upstream service. The conversion is the standard
     * 33-year-cycle day-count algorithm (Pournader), which is exact for the
     * whole range this plugin can produce. The input is a UTC `Y-m-d H:i:s`
     * string as written by Helpers::now(); it is shifted to the site timezone
     * FIRST, because a customer in Tehran reading a 21:00 UTC timestamp would
     * otherwise be shown the previous day.
     *
     * Supported format letters: j d n m F Y y H i s (everything else is copied
     * through literally; escape with a backslash).
     */
    public static function jdate(string $utc_datetime, string $format = 'j F Y'): string
    {
        $utc_datetime = trim($utc_datetime);
        if ($utc_datetime === '' || strpos($utc_datetime, '0000-00-00') === 0) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($utc_datetime, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return '';
        }
        $dt = $dt->setTimezone(self::site_timezone());

        [$jy, $jm, $jd] = self::to_jalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));

        $out = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $c = $format[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $format[++$i];
                continue;
            }
            switch ($c) {
                case 'j': $out .= (string) $jd; break;
                case 'd': $out .= str_pad((string) $jd, 2, '0', STR_PAD_LEFT); break;
                case 'n': $out .= (string) $jm; break;
                case 'm': $out .= str_pad((string) $jm, 2, '0', STR_PAD_LEFT); break;
                case 'F': $out .= self::JALALI_MONTHS[$jm - 1]; break;
                case 'Y': $out .= (string) $jy; break;
                case 'y': $out .= substr((string) $jy, -2); break;
                case 'H': $out .= $dt->format('H'); break;
                case 'i': $out .= $dt->format('i'); break;
                case 's': $out .= $dt->format('s'); break;
                default:  $out .= $c;
            }
        }
        return self::fa_digits($out);
    }

    /** @return int[] [jy, jm, jd] */
    public static function to_jalali(int $gy, int $gm, int $gd): array
    {
        // Days elapsed since the Gregorian epoch, then re-bucketed onto the
        // Jalali 33-year cycle (12053 days) → 4-year cycle (1461 days) → year.
        $g_days_in_month = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2  = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) + $gd + $g_days_in_month[$gm - 1];

        $jy    = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy   += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy  += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    private static function site_timezone(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        return new \DateTimeZone('Asia/Tehran');
    }

    /**
     * Persian label for a service connection key. Providers hand back
     * snake_case identifiers (`password_hint`, `access_key_hint`); the screen a
     * customer sees right after paying must not read as developer output.
     * Unknown keys fall back to the key itself so a new provider field degrades
     * to today's behaviour instead of vanishing.
     */
    public static function connection_label(string $key): string
    {
        $map = [
            'ip'              => __('نشانی IP', 'arvan-reseller'),
            'ipv4'            => __('نشانی IPv4', 'arvan-reseller'),
            'ipv6'            => __('نشانی IPv6', 'arvan-reseller'),
            'host'            => __('میزبان', 'arvan-reseller'),
            'port'            => __('پورت', 'arvan-reseller'),
            'user'            => __('نام کاربری', 'arvan-reseller'),
            'username'        => __('نام کاربری', 'arvan-reseller'),
            'password'        => __('گذرواژه', 'arvan-reseller'),
            'password_hint'   => __('گذرواژه', 'arvan-reseller'),
            'image'           => __('سیستم‌عامل', 'arvan-reseller'),
            'region'          => __('منطقه', 'arvan-reseller'),
            'flavor'          => __('پلن سخت‌افزاری', 'arvan-reseller'),
            'domain'          => __('دامنه', 'arvan-reseller'),
            'cname'           => __('رکورد CNAME', 'arvan-reseller'),
            'ns1'             => __('سرور نام ۱', 'arvan-reseller'),
            'ns2'             => __('سرور نام ۲', 'arvan-reseller'),
            'bucket'          => __('نام باکت', 'arvan-reseller'),
            'endpoint'        => __('نشانی سرویس', 'arvan-reseller'),
            'access_key'      => __('کلید دسترسی', 'arvan-reseller'),
            'access_key_hint' => __('کلید دسترسی', 'arvan-reseller'),
            'secret_key'      => __('کلید محرمانه', 'arvan-reseller'),
            'secret_key_hint' => __('کلید محرمانه', 'arvan-reseller'),
            'status'          => __('وضعیت', 'arvan-reseller'),
            'name'            => __('نام سرویس', 'arvan-reseller'),
            'url'             => __('نشانی', 'arvan-reseller'),
        ];
        return $map[$key] ?? $key;
    }
}
