<?php
namespace ArvanReseller\Admin;

use ArvanReseller\Install\PageFactory;

defined('ABSPATH') || exit;

/**
 * Internal identifiers → Persian admin labels.
 *
 * The admin used to print raw snake_case internals — `pending_payment`,
 * `usage_debit`, `object_storage` — directly beside the same values rendered
 * in Persian by Helpers::status_tag, which is what made the panel read as
 * half-localised in exactly the columns an operator scans fastest. Anything
 * unmapped falls back to the raw key rather than to an empty cell: an
 * unlabelled event is still evidence.
 */
final class Labels
{
    public static function ledger_type(string $type): string
    {
        $map = [
            'topup'          => __('شارژ کیف پول', 'arvan-reseller'),
            'payment'        => __('پرداخت سفارش', 'arvan-reseller'),
            'refund'         => __('بازپرداخت', 'arvan-reseller'),
            'promo_credit'   => __('اعتبار هدیه', 'arvan-reseller'),
            'release'        => __('آزادسازی رزرو', 'arvan-reseller'),
            'purchase'       => __('خرید سرویس', 'arvan-reseller'),
            'usage_debit'    => __('برداشت مصرف', 'arvan-reseller'),
            'service_charge' => __('هزینه تمدید', 'arvan-reseller'),
            'adjustment'     => __('اصلاح دستی', 'arvan-reseller'),
            'reservation'    => __('رزرو اعتبار', 'arvan-reseller'),
        ];
        return $map[$type] ?? $type;
    }

    public static function job_type(string $type): string
    {
        $map = [
            'provision_order'   => __('راه‌اندازی سفارش', 'arvan-reseller'),
            'usage_sync'        => __('همگام‌سازی مصرف', 'arvan-reseller'),
            'renew_services'    => __('تمدید سرویس‌ها', 'arvan-reseller'),
            'renewal_reminders' => __('یادآوری تمدید', 'arvan-reseller'),
            'poll_service'      => __('پی‌گیری وضعیت سرویس', 'arvan-reseller'),
            'credential_health' => __('بررسی سلامت اتصال', 'arvan-reseller'),
            'prune'             => __('پاک‌سازی داده‌های قدیمی', 'arvan-reseller'),
            'repair_ledger'     => __('ترمیم دفتر کل', 'arvan-reseller'),
        ];
        return $map[$type] ?? $type;
    }

    public static function notification_type(string $type): string
    {
        $map = [
            'provision_failed'   => __('خطای راه‌اندازی', 'arvan-reseller'),
            'job_dead'           => __('وظیفه متوقف', 'arvan-reseller'),
            'usage_sync_failed'  => __('خطای همگام‌سازی مصرف', 'arvan-reseller'),
            'customer_at_risk'   => __('مشتری در معرض خطر', 'arvan-reseller'),
            'credential_failed'  => __('خطای اتصال ArvanCloud', 'arvan-reseller'),
            'renewal_no_price'   => __('تمدید بدون قیمت', 'arvan-reseller'),
            'renewal_charged'    => __('تمدید انجام‌شده', 'arvan-reseller'),
            'renewal_reminder'   => __('یادآوری تمدید', 'arvan-reseller'),
            'renewal_cancelled'  => __('لغو تمدید', 'arvan-reseller'),
            'payment_success'    => __('پرداخت موفق', 'arvan-reseller'),
            'provisioned'        => __('سرویس تحویل شد', 'arvan-reseller'),
            'low_balance'        => __('اعتبار کم', 'arvan-reseller'),
            'critical_balance'   => __('اعتبار بحرانی', 'arvan-reseller'),
            'suspension_warning' => __('هشدار تعلیق', 'arvan-reseller'),
            'ledger_repair'      => __('ترمیم دفتر کل', 'arvan-reseller'),
            'amount_mismatch'    => __('مغایرت مبلغ پرداخت', 'arvan-reseller'),
        ];
        return $map[$type] ?? $type;
    }

    /** Store page keys already carry Persian titles; the wizard printed the key. */
    public static function page_title(string $key): string
    {
        $definitions = PageFactory::definitions();
        return isset($definitions[$key]['title']) ? (string) $definitions[$key]['title'] : $key;
    }
}
