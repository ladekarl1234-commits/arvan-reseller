<?php
use ArvanReseller\Wallet\Ledger;
use PHPUnit\Framework\TestCase;

final class LedgerDerivationTest extends TestCase
{
    private function entry(string $type, int $amount): array
    {
        return ['direction' => Ledger::direction_of($type), 'amount' => $amount, 'type' => $type];
    }

    public function test_balance_is_derived_from_entries(): void
    {
        $balance = Ledger::derive([
            $this->entry('topup', 20000000),
            $this->entry('purchase', 12000000),
            $this->entry('usage_debit', 500000),
        ]);
        $this->assertSame(7500000, $balance['available']);
        $this->assertSame(12500000, $balance['consumed']);
        $this->assertSame(20000000, $balance['topup_total']);
    }

    public function test_reservation_and_release_net_out(): void
    {
        $balance = Ledger::derive([
            $this->entry('topup', 1000000),
            $this->entry('reservation', 400000),
            $this->entry('release', 400000),
        ]);
        $this->assertSame(1000000, $balance['available']);
        $this->assertSame(0, $balance['reserved']);
    }

    public function test_open_reservation_reduces_available(): void
    {
        $balance = Ledger::derive([
            $this->entry('topup', 1000000),
            $this->entry('reservation', 400000),
        ]);
        $this->assertSame(600000, $balance['available']);
        $this->assertSame(400000, $balance['reserved']);
    }

    public function test_refund_and_promo_are_credits(): void
    {
        $balance = Ledger::derive([
            $this->entry('purchase', 500000),
            $this->entry('refund', 500000),
            $this->entry('promo_credit', 100000),
        ]);
        $this->assertSame(100000, $balance['available']);
    }

    public function test_balance_can_go_negative(): void
    {
        $balance = Ledger::derive([
            $this->entry('topup', 100000),
            $this->entry('usage_debit', 250000),
        ]);
        $this->assertSame(-150000, $balance['available']);
    }

    public function test_direction_mapping_is_exhaustive_and_rejects_unknown(): void
    {
        foreach (Ledger::CREDIT_TYPES as $type) {
            $this->assertSame('credit', Ledger::direction_of($type));
        }
        foreach (Ledger::DEBIT_TYPES as $type) {
            $this->assertSame('debit', Ledger::direction_of($type));
        }
        $this->assertNull(Ledger::direction_of('steal_money'));
    }
}
