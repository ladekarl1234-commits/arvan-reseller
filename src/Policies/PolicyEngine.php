<?php
namespace ArvanReseller\Policies;

/**
 * Pure credit-policy evaluation (spec §5.5). Stages derive from balance vs
 * configurable thresholds; ACTIONS are decided separately by the caller from
 * settings — evaluation never mutates anything and never destroys resources.
 * Aligned with ArvanCloud's published depletion flow (warning → suspension →
 * eventual removal by Arvan itself); this plugin only ever notifies/blocks/
 * suspends-via-documented-API. (spec: never auto-destroy on local math.)
 */
final class PolicyEngine
{
    public const HEALTHY    = 'healthy';
    public const WARNING    = 'warning';
    public const CRITICAL   = 'critical';
    public const GRACE      = 'grace';
    public const RESTRICTED = 'restricted';

    /**
     * @param int      $balance           available credit (IRT, may be negative)
     * @param int      $warning_threshold e.g. 500,000
     * @param int      $critical_threshold e.g. 100,000
     * @param int      $grace_days        days of grace after balance <= 0
     * @param int|null $negative_since_days days the balance has been <= 0 (null = not negative)
     */
    public static function stage(
        int $balance,
        int $warning_threshold,
        int $critical_threshold,
        int $grace_days,
        ?int $negative_since_days = null
    ): string {
        if ($balance <= 0) {
            if ($negative_since_days !== null && $negative_since_days > $grace_days) {
                return self::RESTRICTED;
            }
            return self::GRACE;
        }
        if ($balance <= $critical_threshold) {
            return self::CRITICAL;
        }
        if ($balance <= $warning_threshold) {
            return self::WARNING;
        }
        return self::HEALTHY;
    }

    /**
     * Which configured actions apply at a stage. Actions themselves are
     * executed by callers (Notifier, checkout guard, admin flags).
     *
     * @param string   $stage
     * @param string[] $enabled_actions subset of the known action keys
     * @return string[]
     */
    public static function actions_for(string $stage, array $enabled_actions): array
    {
        $matrix = [
            self::HEALTHY    => [],
            self::WARNING    => ['notify_customer'],
            self::CRITICAL   => ['notify_customer', 'notify_admin'],
            self::GRACE      => ['notify_customer', 'notify_admin', 'mark_at_risk'],
            self::RESTRICTED => ['notify_customer', 'notify_admin', 'mark_at_risk', 'block_purchases', 'suspend_service'],
        ];
        return array_values(array_intersect($matrix[$stage] ?? [], $enabled_actions));
    }
}
