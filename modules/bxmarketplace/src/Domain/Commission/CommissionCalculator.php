<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Commission;

/**
 * Vendor-specific commission engine.
 *
 *  line commission  = round(unit_price · qty · rate% + fixed_per_line, 2)   (never more than the line value)
 *  order commission = Σ line commissions (+ rate% of shipping when applyOnShipping)
 *  vendor payout    = products + shipping − commission
 *  settlement       = Σ gross (total_paid) − Σ commission + Σ adjustments  over non-cancelled vendor orders;
 *                     adjustments: positive = credit to vendor, negative = deduction.
 *
 * All amounts tax excluded, rounded half-up to cents.
 */
final class CommissionCalculator
{
    public function lineCommission(float $unitPrice, int $quantity, CommissionTerms $terms): float
    {
        $value = $unitPrice * $quantity;
        if ($value <= 0) {
            return 0.0;
        }
        $c = $value * $terms->ratePercent / 100 + $terms->fixedPerLine;

        return self::round(min($c, $value));
    }

    /**
     * @param array<int|string, array{unit_price:float|int|string,quantity:int|string}> $lines
     */
    public function calculateOrder(array $lines, float $shipping, CommissionTerms $terms): OrderCommission
    {
        $perLine = [];
        $products = 0.0;
        $commission = 0.0;
        foreach ($lines as $k => $line) {
            $unit = (float) $line['unit_price'];
            $qty = (int) $line['quantity'];
            $perLine[$k] = $this->lineCommission($unit, $qty, $terms);
            $products += $unit * $qty;
            $commission += $perLine[$k];
        }
        if ($terms->applyOnShipping && $shipping > 0) {
            $commission += self::round($shipping * $terms->ratePercent / 100);
        }

        return new OrderCommission($perLine, self::round($products), self::round($shipping), self::round($commission));
    }

    /**
     * @param array<int, array{total_paid:float|string,commission_amount:float|string,status?:string}> $vendorOrders
     * @param array<int, array{amount:float|string}|float> $adjustments
     */
    public function settle(array $vendorOrders, array $adjustments = []): SettlementTotals
    {
        $gross = 0.0;
        $commission = 0.0;
        $count = 0;
        foreach ($vendorOrders as $o) {
            if (($o['status'] ?? '') === 'cancelled') {
                continue;
            }
            $gross += (float) $o['total_paid'];
            $commission += (float) $o['commission_amount'];
            ++$count;
        }
        $adj = 0.0;
        foreach ($adjustments as $a) {
            $adj += is_array($a) ? (float) $a['amount'] : (float) $a;
        }

        return new SettlementTotals(self::round($gross), self::round($commission), self::round($adj), $count);
    }

    /**
     * Payment allocation of one customer payment across vendors (+ platform commission).
     *
     * @param array<int, OrderCommission> $byVendor idVendor => commission breakdown
     *
     * @return array{vendors: array<int, float>, platform: float, total: float}
     */
    public function splitPayment(array $byVendor): array
    {
        $vendors = [];
        $platform = 0.0;
        $total = 0.0;
        foreach ($byVendor as $idVendor => $oc) {
            $vendors[$idVendor] = $oc->vendorPayout();
            $platform += $oc->commission;
            $total += $oc->totalPaid();
        }

        return ['vendors' => $vendors, 'platform' => self::round($platform), 'total' => self::round($total)];
    }

    public static function round(float $v): float
    {
        return round($v + ($v >= 0 ? 1e-9 : -1e-9), 2);
    }
}
