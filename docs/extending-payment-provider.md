# Extending: implementing a real payment gateway

Proof-by-walkthrough that the payment architecture is actually extensible. This adds a hypothetical Zarinpal-style PSP as a companion plugin (or a class inside a fork) **without touching any core file**.

## The contract

`src/Payments/PaymentProviderInterface.php` — two meaningful methods:

```php
interface PaymentProviderInterface {
    public function id(): string;                 // 'zarinpal'
    public function label(): string;              // Persian display name
    public function start(string $ref, int $amount, string $type): string;  // URL to send the customer to
    public function verify(string $ref, int $amount, array $payload): array; // ['ok','transaction_id','message']
}
```

The core guarantees around you (you do NOT reimplement these): amount is recomputed server-side before `start()`; the callback claim is atomic and replay-safe; ledger writes are idempotent; provisioning is triggered exactly once.

## Implementation

```php
final class ZarinpalProvider implements PaymentProviderInterface {
    public function id(): string { return 'zarinpal'; }
    public function label(): string { return 'زرین‌پال'; }

    public function start(string $ref, int $amount, string $type): string {
        $response = wp_remote_post('https://payment.zarinpal.com/pg/v4/payment/request.json', [
            'timeout' => 15,
            'body' => wp_json_encode([
                'merchant_id' => $this->merchant_id(),
                'amount'      => $amount * 10, // IRT → IRR
                'callback_url'=> rest_url('arvan-reseller/v1/payment/callback')
                                 . '?ref=' . rawurlencode($ref) . '&type=' . $type,
                'description' => 'سفارش ' . $ref,
            ]),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $authority = json_decode(wp_remote_retrieve_body($response), true)['data']['authority'] ?? '';
        update_option('arvrs_zp_' . $ref, $authority, false); // bind authority↔ref server-side
        return 'https://payment.zarinpal.com/pg/StartPay/' . $authority;
    }

    public function verify(string $ref, int $amount, array $payload): array {
        // NEVER trust the redirect: confirm with the PSP's verify endpoint,
        // binding BOTH the stored authority and the expected amount.
        $authority = (string) get_option('arvrs_zp_' . $ref, '');
        $response  = wp_remote_post('https://payment.zarinpal.com/pg/v4/payment/verify.json', [
            'timeout' => 15,
            'body'    => wp_json_encode(['merchant_id' => $this->merchant_id(), 'amount' => $amount * 10, 'authority' => $authority]),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $data = json_decode(wp_remote_retrieve_body($response), true)['data'] ?? [];
        $ok   = in_array((int) ($data['code'] ?? 0), [100, 101], true); // 101 = already verified → core replay path handles it
        return ['ok' => $ok, 'transaction_id' => (string) ($data['ref_id'] ?? ''), 'message' => $ok ? 'پرداخت تأیید شد.' : 'تأیید ناموفق بود.'];
    }

    private function merchant_id(): string { /* your settings storage */ }
}
```

## Registration

```php
add_filter('arvrs_payment_provider', fn() => new ZarinpalProvider());
```

That's the entire integration surface — `Plugin::payments()` validates the filter result implements the interface and falls back to sandbox otherwise.

## Callback payload note

The core callback route accepts `ref`, `type` and provider fields; if your PSP posts extra fields (e.g. `Authority`, `Status`), whitelist them by filtering the REST args — or, simpler, have `start()` put everything needed into the server-side option keyed by `ref` as shown, so the callback needs nothing beyond `ref`.

## Checklist before shipping a real adapter

- [ ] `verify()` calls the PSP's server-side verify API (never trusts redirect params)
- [ ] Amount is passed to and checked by the PSP verify call
- [ ] The authority/token binding is stored server-side at `start()` time
- [ ] "Already verified" PSP responses return `ok=true` so the core replay path answers idempotently
- [ ] Secrets (merchant ID/API key) stored via the same `Crypto` class, masked in UI
- [ ] Unit test the verify decision table with mocked `wp_remote_post` (see `PaymentVerificationTest` for the shape)
