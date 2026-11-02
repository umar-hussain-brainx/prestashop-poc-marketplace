<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Service;

use BrainX\Marketplace\Domain\Commission\CommissionCalculator;
use BrainX\Marketplace\Domain\Commission\SettlementTotals;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceAdmin\Domain\Period;
use BrainX\MarketplaceAdmin\Domain\ReconciliationChecker;
use BrainX\MarketplaceAdmin\Repository\FinanceRepository;

/**
 * Vendor settlements: draft → approved → paid.
 *
 * Totals come from the core CommissionCalculator::settle() over the vendor orders created in the period
 * (cancelled excluded) and the commission adjustments dated in the period. Regenerating the same vendor+period
 * refreshes the draft; a period overlapping an approved/paid settlement is refused.
 */
final class SettlementService
{
    public const TRANSITIONS = ['draft' => ['approved'], 'approved' => ['paid', 'draft'], 'paid' => []];

    public function __construct(
        private readonly FinanceRepository $finance = new FinanceRepository(),
        private readonly CommissionCalculator $calculator = new CommissionCalculator(),
    ) {
    }

    /** @return array{totals: SettlementTotals, orders: array, adjustments: array} */
    public function compute(int $idVendor, string $from, string $to): array
    {
        $period = new Period(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
        $orders = $this->finance->vendorOrders($period, ['id_vendor' => $idVendor], 100000);
        $adjustments = $this->finance->adjustments($period, $idVendor);

        return ['totals' => $this->calculator->settle($orders, $adjustments), 'orders' => $orders, 'adjustments' => $adjustments];
    }

    /**
     * Create (or refresh the existing draft of) the settlement of a vendor for a period.
     *
     * @throws \RuntimeException when the period overlaps an approved/paid settlement
     */
    public function generate(int $idVendor, string $from, string $to): \BxmpSettlement
    {
        if (!\Validate::isDate($from) || !\Validate::isDate($to) || $from > $to) {
            throw new \RuntimeException('Invalid settlement period');
        }
        if (!(new \BxmpVendor($idVendor))->id) {
            throw new \RuntimeException('Unknown vendor #' . $idVendor);
        }
        if ($locked = $this->finance->overlappingLocked($idVendor, $from, $to)) {
            throw new \RuntimeException(sprintf('Period overlaps settlement #%d (%s, %s → %s)', $locked['id_settlement'], $locked['status'], $locked['period_from'], $locked['period_to']));
        }
        $totals = $this->compute($idVendor, $from, $to)['totals'];
        $s = new \BxmpSettlement($this->finance->findDraft($idVendor, $from, $to) ?: null);
        $s->id_vendor = $idVendor;
        $s->period_from = $from;
        $s->period_to = $to;
        $s->gross = $totals->gross;
        $s->commission = $totals->commission;
        $s->adjustments = $totals->adjustments;
        $s->net_payout = $totals->netPayout();
        $s->status = 'draft';
        if (!$s->save()) {
            throw new \RuntimeException('Could not save the settlement');
        }
        Logger::channel('settlement')->info('Settlement generated', ['id_settlement' => (int) $s->id, 'id_vendor' => $idVendor] + $totals->toArray());

        return $s;
    }

    public function transition(int $idSettlement, string $to): \BxmpSettlement
    {
        $s = new \BxmpSettlement($idSettlement);
        if (!$s->id) {
            throw new \RuntimeException('Unknown settlement');
        }
        $from = (string) $s->status;
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \RuntimeException(sprintf('Transition %s → %s is not allowed', $from, $to));
        }
        if ($to === 'approved') {
            if ($other = $this->finance->overlappingLocked((int) $s->id_vendor, (string) $s->period_from, (string) $s->period_to, (int) $s->id)) {
                throw new \RuntimeException(sprintf('Period overlaps settlement #%d', $other['id_settlement']));
            }
            // freeze the latest figures at approval time
            $totals = $this->compute((int) $s->id_vendor, (string) $s->period_from, (string) $s->period_to)['totals'];
            $s->gross = $totals->gross;
            $s->commission = $totals->commission;
            $s->adjustments = $totals->adjustments;
            $s->net_payout = $totals->netPayout();
        }
        $s->status = $to;
        $s->save();
        Logger::channel('settlement')->info('Settlement status changed', ['id_settlement' => (int) $s->id, 'from' => $from, 'to' => $to, 'net_payout' => (float) $s->net_payout]);

        return $s;
    }

    public function deleteDraft(int $idSettlement): void
    {
        $s = new \BxmpSettlement($idSettlement);
        if (!$s->id || $s->status !== 'draft') {
            throw new \RuntimeException('Only draft settlements can be deleted');
        }
        $s->delete();
        Logger::channel('settlement')->info('Draft settlement deleted', ['id_settlement' => $idSettlement]);
    }

    /** Re-compute every draft settlement of a vendor (after recalculations / new adjustments). */
    public function refreshDrafts(int $idVendor): int
    {
        $n = 0;
        foreach ($this->finance->settlements(['id_vendor' => $idVendor, 'status' => 'draft']) as $row) {
            $this->generate($idVendor, (string) $row['period_from'], (string) $row['period_to']);
            ++$n;
        }

        return $n;
    }

    /** Settlement rows enriched with `computed` live totals (for staleness detection). */
    public function withComputed(array $settlements): array
    {
        foreach ($settlements as &$s) {
            $t = $this->compute((int) $s['id_vendor'], (string) $s['period_from'], (string) $s['period_to'])['totals'];
            $s['computed'] = ['gross' => $t->gross, 'commission' => $t->commission, 'adjustments' => $t->adjustments, 'net_payout' => $t->netPayout(), 'orders' => $t->ordersCount];
        }

        return $settlements;
    }

    /**
     * Full reconciliation for a period (and optional vendor): vendor orders vs payouts vs commissions vs adjustments
     * vs transfer references vs PrestaShop parent orders vs stored settlements.
     */
    public function reconcile(Period $p, ?int $idVendor = null): array
    {
        $commission = new CommissionService($this->finance, $this->calculator);
        $orders = $commission->withExpected($this->finance->vendorOrders($p, ['id_vendor' => $idVendor], 100000));
        $adjustments = $this->finance->adjustments($p, $idVendor);
        $settlements = $this->withComputed($this->finance->settlements(['id_vendor' => $idVendor, 'period' => $p]));
        $result = (new ReconciliationChecker())->check($orders, $adjustments, $settlements);
        $names = array_column($this->finance->vendors(), 'name', 'id_vendor');
        foreach ($result['vendors'] as $id => &$v) {
            $v['vendor_name'] = $names[$id] ?? ('#' . $id);
            $v['settled'] = 0.0;
            foreach ($settlements as $s) {
                if ((int) $s['id_vendor'] === $id && $s['status'] !== 'draft') {
                    $v['settled'] += (float) $s['net_payout'];
                }
            }
        }
        unset($v);
        foreach ($result['issues'] as &$i) {
            $i['vendor_name'] = isset($i['id_vendor']) ? ($names[$i['id_vendor']] ?? '') : '';
        }

        return $result + ['orders' => $orders, 'adjustments' => $adjustments, 'settlements' => $settlements];
    }
}
