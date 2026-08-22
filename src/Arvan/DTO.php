<?php
namespace ArvanReseller\Arvan;

/**
 * Value objects crossing the provider boundary (ADR-0005). Small on purpose:
 * they normalize the two providers (Real/Demo) to one shape so the rest of
 * the application never sees raw upstream arrays.
 */
final class Plan
{
    public $id;
    public $product;
    public $name;
    public $specs;      // array<string,string> human-readable spec lines (fa)
    public $base_cost;  // int IRT / month — from BaseCosts, NOT from upstream
    public $meta;       // array upstream extras (region/image constraints etc.)

    public function __construct(string $id, string $product, string $name, array $specs = [], int $base_cost = 0, array $meta = [])
    {
        $this->id = $id;
        $this->product = $product;
        $this->name = $name;
        $this->specs = $specs;
        $this->base_cost = $base_cost;
        $this->meta = $meta;
    }

    public function to_array(): array
    {
        return [
            'id' => $this->id, 'product' => $this->product, 'name' => $this->name,
            'specs' => $this->specs, 'base_cost' => $this->base_cost, 'meta' => $this->meta,
        ];
    }
}

final class RemoteResource
{
    public $remote_id;
    public $status;      // creating|active|failed|suspended
    public $connection;  // array<string,string> customer-facing connection info
    public $raw;         // array redacted upstream payload kept for management

    /**
     * Correlation id of the upstream call that produced this resource, so the
     * order timeline can carry a traceable id on the SUCCESS path too — until
     * now only failures recorded one, which is the wrong half (EX-082).
     * @var string
     */
    public $correlation_id = '';

    public function __construct(string $remote_id, string $status, array $connection = [], array $raw = [], string $correlation_id = '')
    {
        $this->remote_id = $remote_id;
        $this->status = $status;
        $this->connection = $connection;
        $this->raw = $raw;
        $this->correlation_id = $correlation_id;
    }
}

final class UsageRow
{
    public $remote_id;
    public $period_start; // 'Y-m-d H:i:s' UTC
    public $period_end;
    public $quantity;     // float
    public $unit;
    public $cost;         // int IRT for the period

    public function __construct(string $remote_id, string $period_start, string $period_end, float $quantity, string $unit, int $cost)
    {
        $this->remote_id = $remote_id;
        $this->period_start = $period_start;
        $this->period_end = $period_end;
        $this->quantity = $quantity;
        $this->unit = $unit;
        $this->cost = $cost;
    }
}

/** Normalized provider failure — never leaks upstream bodies to customers. */
final class ProviderError extends \RuntimeException
{
    /**
     * auth|rate_limit|timeout|timeout_indeterminate|invalid|conflict|billing|unavailable|unknown
     *
     * `timeout_indeterminate` is the load-bearing one: it is raised when a
     * NON-idempotent upstream write (POST/PATCH) times out or returns 5xx, so
     * the remote side may or may not have acted. It must never be answered by
     * repeating the write — only by reconciling against the remote resource
     * under its deterministic name.
     * @var string
     */
    public $kind;
    public $correlation_id;

    public function __construct(string $kind, string $message, string $correlation_id = '')
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->correlation_id = $correlation_id;
    }

    /**
     * Whether re-running the whole operation later is safe.
     *
     * `timeout_indeterminate` and `conflict` qualify ONLY because every
     * RealProvider create looks the resource up under its deterministic name
     * before it would ever issue a write; a later attempt therefore finds and
     * adopts the existing resource instead of minting a second one. Nothing
     * else may replay them. `auth`, `invalid` and `billing` are permanent
     * until a human acts, so retrying them only delays the alert.
     */
    public function retryable(): bool
    {
        return in_array($this->kind, ['timeout', 'timeout_indeterminate', 'conflict', 'unavailable', 'rate_limit', 'unknown'], true);
    }

    /** Actionable Persian message safe for customers (SEC-12). */
    public function customer_message(): string
    {
        switch ($this->kind) {
            case 'auth':
                return __('پیکربندی سرویس‌دهنده ابری نیاز به بررسی دارد. لطفاً با پشتیبانی تماس بگیرید.', 'arvan-reseller');
            case 'rate_limit':
                return __('درخواست‌ها موقتاً زیاد است. چند دقیقه دیگر دوباره تلاش کنید.', 'arvan-reseller');
            case 'timeout':
                return __('پاسخ سرویس ابری دیر شد. سفارش شما محفوظ است و به‌زودی دوباره تلاش می‌شود.', 'arvan-reseller');
            case 'timeout_indeterminate':
                return __('نتیجه ساخت سرویس هنوز از سمت آروان قطعی نشده است. سفارش شما محفوظ است، سرویس تکراری ساخته نمی‌شود و نتیجه به شما اطلاع داده می‌شود.', 'arvan-reseller');
            case 'conflict':
                return __('این سرویس پیش‌تر ساخته شده است. در حال بازیابی اطلاعات همان سرویس هستیم.', 'arvan-reseller');
            case 'billing':
                return __('حساب سرویس‌دهنده ابری اعتبار کافی ندارد. سفارش شما محفوظ است و پشتیبانی پیگیری می‌کند.', 'arvan-reseller');
            case 'invalid':
                return __('پیکربندی انتخابی در حال حاضر قابل ارائه نیست. گزینه دیگری را انتخاب کنید.', 'arvan-reseller');
            case 'unavailable':
                return __('سرویس ابری موقتاً در دسترس نیست. سفارش شما محفوظ است.', 'arvan-reseller');
        }
        return __('خطای غیرمنتظره‌ای رخ داد. سفارش شما محفوظ است و پیگیری می‌شود.', 'arvan-reseller');
    }
}
