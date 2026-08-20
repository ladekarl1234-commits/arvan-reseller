<?php
namespace ArvanReseller\Payments;

use ArvanReseller\Install\PageFactory;

defined('ABSPATH') || exit;

/**
 * Sandbox gateway (ADR-0010): a fully working on-site payment simulation so
 * the complete flow — including duplicate-callback replay safety — can be
 * demonstrated without external credentials. The "gateway secret" is an HMAC
 * over (ref|amount|type) keyed by a WP salt, so verify() is a REAL
 * server-side check: a tampered amount or ref fails exactly like a real
 * gateway's verification would.
 */
final class SandboxProvider implements PaymentProviderInterface
{
    public function id(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return __('درگاه آزمایشی (سندباکس)', 'arvan-reseller');
    }

    public function start(string $ref, int $amount, string $type): string
    {
        return add_query_arg([
            'arvrs_ref'  => rawurlencode($ref),
            'arvrs_type' => $type,
        ], PageFactory::url('payment'));
    }

    /** The token a successful sandbox payment must present. */
    public static function proof(string $ref, int $amount, string $type): string
    {
        return hash_hmac('sha256', $ref . '|' . $amount . '|' . $type, wp_salt('nonce'));
    }

    public function verify(string $ref, int $amount, array $payload): array
    {
        $given = (string) ($payload['sandbox_proof'] ?? '');
        $ok    = $given !== '' && hash_equals(self::proof($ref, $amount, (string) ($payload['type'] ?? 'order')), $given);
        return [
            'ok'             => $ok,
            'transaction_id' => $ok ? 'SBX-' . strtoupper(substr(hash('crc32b', $given), 0, 8)) : '',
            'message'        => $ok
                ? __('پرداخت با موفقیت تأیید شد.', 'arvan-reseller')
                : __('تأیید پرداخت ناموفق بود.', 'arvan-reseller'),
        ];
    }
}
