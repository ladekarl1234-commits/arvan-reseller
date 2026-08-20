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

    public function __construct(string $remote_id, string $status, array $connection = [], array $raw = [])
    {
        $this->remote_id = $remote_id;
        $this->status = $status;
        $this->connection = $connection;
        $this->raw = $raw;
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
    public $kind;          // auth|rate_limit|timeout|invalid|unavailable|unknown
    public $correlation_id;

    public function __construct(string $kind, string $message, string $correlation_id = '')
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->correlation_id = $correlation_id;
    }

    /** Actionable Persian message safe for customers (SEC-12). */
    public function customer_message(): string
    {
        $map = [
            'auth'        => 'پیکربندی سرویس‌دهنده ابری نیاز به بررسی دارد. لطفاً با پشتیبانی تماس بگیرید.',
            'rate_limit'  => 'درخواست‌ها موقتاً زیاد است. چند دقیقه دیگر دوباره تلاش کنید.',
            'timeout'     => 'پاسخ سرویس ابری دیر شد. سفارش شما محفوظ است و به‌زودی دوباره تلاش می‌شود.',
            'invalid'     => 'پیکربندی انتخابی در حال حاضر قابل ارائه نیست. گزینه دیگری را انتخاب کنید.',
            'unavailable' => 'سرویس ابری موقتاً در دسترس نیست. سفارش شما محفوظ است.',
        ];
        return $map[$this->kind] ?? 'خطای غیرمنتظره‌ای رخ داد. سفارش شما محفوظ است و پیگیری می‌شود.';
    }
}
