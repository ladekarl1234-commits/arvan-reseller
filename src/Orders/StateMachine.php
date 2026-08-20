<?php
namespace ArvanReseller\Orders;

/**
 * Pure order state machine (spec §5.2). No WordPress dependency — unit-tested
 * directly. OrderService is the only writer and consults this table before
 * any persistence.
 */
final class StateMachine
{
    public const DRAFT              = 'draft';
    public const PENDING_PAYMENT    = 'pending_payment';
    public const PAYMENT_PROCESSING = 'payment_processing';
    public const PAID               = 'paid';
    public const PROVISIONING       = 'provisioning';
    public const ACTIVE             = 'active';
    public const PROVISION_FAILED   = 'provision_failed';
    public const CANCELLED          = 'cancelled';
    public const REFUNDED           = 'refunded';

    /** @var array<string,string[]> allowed transitions: from => [to, ...] */
    private const TRANSITIONS = [
        self::DRAFT              => [self::PENDING_PAYMENT, self::CANCELLED],
        self::PENDING_PAYMENT    => [self::PAYMENT_PROCESSING, self::PAID, self::CANCELLED],
        self::PAYMENT_PROCESSING => [self::PAID, self::PENDING_PAYMENT, self::CANCELLED],
        self::PAID               => [self::PROVISIONING, self::PROVISION_FAILED, self::REFUNDED],
        self::PROVISIONING       => [self::ACTIVE, self::PROVISION_FAILED],
        self::PROVISION_FAILED   => [self::PROVISIONING, self::REFUNDED],
        self::ACTIVE             => [self::CANCELLED, self::REFUNDED],
        self::CANCELLED          => [],
        self::REFUNDED           => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /** States a customer may still pay from. */
    public static function payable(): array
    {
        return [self::PENDING_PAYMENT, self::PAYMENT_PROCESSING];
    }

    public static function is_terminal(string $state): bool
    {
        return empty(self::TRANSITIONS[$state] ?? []);
    }
}
