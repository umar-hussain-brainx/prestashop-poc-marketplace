<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Commission;

/** Totals of a vendor settlement period (maps to bxmp_settlement columns). */
final class SettlementTotals
{
    public function __construct(
        public readonly float $gross,
        public readonly float $commission,
        public readonly float $adjustments,
        public readonly int $ordersCount,
    ) {
    }

    public function netPayout(): float
    {
        return round($this->gross - $this->commission + $this->adjustments, 2);
    }

    public function toArray(): array
    {
        return [
            'gross' => $this->gross,
            'commission' => $this->commission,
            'adjustments' => $this->adjustments,
            'net_payout' => $this->netPayout(),
            'orders_count' => $this->ordersCount,
        ];
    }
}
