<?php
namespace ArvanReseller\Payments;

/**
 * Payment gateway boundary (ADR-0006). The application only ever: starts a
 * payment for (ref, amount, customer) and later verifies a callback payload
 * server-side. Real Iranian gateways (Zarinpal/IDPay/…) implement this same
 * contract in a future adapter; nothing else changes.
 */
interface PaymentProviderInterface
{
    public function id(): string;

    public function label(): string;

    /**
     * Begin a payment. Returns the URL to send the customer to.
     * @param string $ref    unique payment reference (order payment_ref or topup ref)
     * @param int    $amount IRT
     * @param string $type   'order'|'topup'
     */
    public function start(string $ref, int $amount, string $type): string;

    /**
     * Server-side verification of a callback (SEC: never trust the redirect
     * alone). Implementations MUST validate amount integrity themselves.
     * @param array $payload whitelisted callback fields
     * @return array{ok:bool,transaction_id:string,message:string}
     */
    public function verify(string $ref, int $amount, array $payload): array;
}
