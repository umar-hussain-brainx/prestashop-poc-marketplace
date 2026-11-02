<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Service;

use BrainX\Marketplace\Domain\Commission\CommissionCalculator;
use BrainX\Marketplace\Domain\Commission\CommissionTerms;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\MarketplaceAdmin\Repository\FinanceRepository;

/**
 * Automated commission calculations (core CommissionCalculator) on stored vendor orders:
 * computes the commission each order *should* carry under the vendor's current terms, and recalculates
 * not-yet-settled, non-cancelled orders when a vendor's rate changed (explicit admin confirmation in the UI).
 */
final class CommissionService
{
    public function __construct(
        private readonly FinanceRepository $finance = new FinanceRepository(),
        private readonly CommissionCalculator $calculator = new CommissionCalculator(),
    ) {
    }

    public static function termsFromRow(array $vendorRow): CommissionTerms
    {
        return CommissionTerms::fromVendorRow($vendorRow, (bool) \Configuration::get('BXMP_COMMISSION_ON_SHIPPING'));
    }

    /**
     * Adds expected_commission / expected_payout / drift (+ expected line commissions) to vendor order rows
     * (rows must carry commission_rate / commission_fixed of their vendor, as FinanceRepository::vendorOrders() returns).
     */
    public function withExpected(array $orders): array
    {
        $lines = $this->finance->linesFor(array_column($orders, 'id_vendor_order'));
        foreach ($orders as &$o) {
            $own = $lines[(int) $o['id_vendor_order']] ?? [];
            if (!$own) {
                // no line detail: commission on the products total as a single line
                $own = [['id_vendor_order_line' => 0, 'unit_price' => (float) $o['total_products'], 'quantity' => 1]];
            }
            $input = [];
            foreach ($own as $l) {
                $input[(int) $l['id_vendor_order_line']] = ['unit_price' => (float) $l['unit_price'], 'quantity' => (int) $l['quantity']];
            }
            $oc = $this->calculator->calculateOrder($input, (float) $o['total_shipping'], self::termsFromRow($o));
            $o['expected_commission'] = $oc->commission;
            $o['expected_payout'] = round((float) $o['total_paid'] - $oc->commission, 2);
            $o['expected_line_commissions'] = $oc->lineCommissions;
            $o['drift'] = round($oc->commission - (float) $o['commission_amount'], 2);
            $o['locked'] = !empty($o['id_settlement_locked']);
        }

        return $orders;
    }

    /**
     * Orders whose stored commission differs from the current terms and that can still be changed
     * (not cancelled, not inside an approved/paid settlement).
     */
    public function pendingRecalculation(?int $idVendor = null): array
    {
        $orders = $this->withExpected($this->finance->vendorOrders(null, ['id_vendor' => $idVendor, 'unsettled' => true], 5000));

        return array_values(array_filter($orders, static fn ($o) => $o['status'] !== 'cancelled' && abs($o['drift']) > 0.005));
    }

    /**
     * Apply the recalculation. Returns the changed rows. Draft settlements of the impacted vendors are refreshed.
     *
     * @param int[]|null $onlyIds restrict to these vendor orders (as confirmed by the admin)
     */
    public function recalculate(?int $idVendor = null, ?array $onlyIds = null, ?SettlementService $settlements = null): array
    {
        $changed = [];
        $db = \Db::getInstance();
        foreach ($this->pendingRecalculation($idVendor) as $o) {
            $id = (int) $o['id_vendor_order'];
            if ($onlyIds !== null && !in_array($id, $onlyIds, true)) {
                continue;
            }
            foreach ($o['expected_line_commissions'] as $idLine => $c) {
                if ($idLine > 0) {
                    $db->update('bxmp_vendor_order_line', ['commission_amount' => (float) $c], 'id_vendor_order_line = ' . (int) $idLine);
                }
            }
            $db->update('bxmp_vendor_order', [
                'commission_amount' => $o['expected_commission'],
                'vendor_payout' => $o['expected_payout'],
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_vendor_order = ' . $id);
            $changed[] = ['id_vendor_order' => $id, 'id_vendor' => (int) $o['id_vendor'], 'reference' => $o['reference'],
                'old_commission' => (float) $o['commission_amount'], 'new_commission' => $o['expected_commission'],
                'old_payout' => (float) $o['vendor_payout'], 'new_payout' => $o['expected_payout']];
        }
        if ($changed) {
            Logger::channel('commission')->info('Commissions recalculated with current vendor terms', ['count' => count($changed), 'orders' => $changed]);
            $settlements ??= new SettlementService($this->finance);
            foreach (array_unique(array_column($changed, 'id_vendor')) as $v) {
                $settlements->refreshDrafts((int) $v);
            }
        }

        return $changed;
    }
}
