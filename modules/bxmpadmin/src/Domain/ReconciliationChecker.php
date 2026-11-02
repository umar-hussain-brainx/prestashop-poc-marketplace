<?php

declare(strict_types=1);

namespace BrainX\MarketplaceAdmin\Domain;

/**
 * Financial reconciliation of vendor sub-orders, commission adjustments and settlements (pure PHP).
 *
 * For each vendor it sums gross (vendor order total_paid), commissions, payouts, adjustments and what was actually
 * transferred (payout of orders carrying a payment_transfer_ref), and flags inconsistencies:
 *
 *  - total_mismatch         products + shipping ≠ total_paid                         (error)
 *  - payout_mismatch        total_paid − commission ≠ vendor_payout                  (error)
 *  - negative_payout        vendor_payout < 0                                         (error)
 *  - missing_transfer_ref   live order without payment_transfer_ref                    (warning)
 *  - cancelled_transferred  cancelled order whose funds were transferred (has a ref)   (error)
 *  - cancelled_with_payout  cancelled order still carrying a payout amount             (warning)
 *  - commission_drift       stored commission ≠ commission under current vendor terms  (warning)
 *  - parent_order_mismatch  Σ vendor orders ≠ PrestaShop parent order total (tax excl) (warning)
 *  - settlement_stale       stored settlement totals ≠ recomputed totals                (error if approved/paid, else warning)
 */
final class ReconciliationChecker
{
    public const TOLERANCE = 0.01;

    /**
     * @param array<int, array<string, mixed>> $vendorOrders bxmp_vendor_order rows; optional keys
     *        `expected_commission` (float) and `parent_total` (PS order products+shipping tax excl.)
     * @param array<int, array<string, mixed>> $adjustments  bxmp_commission_adjustment rows
     * @param array<int, array<string, mixed>> $settlements  bxmp_settlement rows with an extra `computed` key
     *        (['gross','commission','adjustments','net_payout']) holding the recomputed totals
     *
     * @return array{vendors: array<int, array<string, float|int>>, totals: array<string, float|int>, issues: array<int, array<string, mixed>>}
     */
    public function check(array $vendorOrders, array $adjustments = [], array $settlements = []): array
    {
        $vendors = [];
        $issues = [];
        $parents = [];
        $blank = ['orders' => 0, 'cancelled' => 0, 'gross' => 0.0, 'commission' => 0.0, 'payout' => 0.0, 'adjustments' => 0.0,
            'net_due' => 0.0, 'transferred' => 0.0, 'untransferred' => 0.0, 'issues' => 0];

        foreach ($vendorOrders as $o) {
            $idVendor = (int) $o['id_vendor'];
            $vendors[$idVendor] ??= $blank;
            $v = &$vendors[$idVendor];
            $cancelled = ($o['status'] ?? '') === 'cancelled';
            $paid = (float) $o['total_paid'];
            $commission = (float) $o['commission_amount'];
            $payout = (float) $o['vendor_payout'];
            $ref = trim((string) ($o['payment_transfer_ref'] ?? ''));
            $ctx = ['id_vendor' => $idVendor, 'id_vendor_order' => (int) $o['id_vendor_order'], 'reference' => (string) ($o['reference'] ?? '')];

            $idOrder = (int) ($o['id_order'] ?? 0);
            if ($idOrder > 0 && array_key_exists('parent_total', $o) && $o['parent_total'] !== null) {
                $parents[$idOrder] ??= ['parent' => (float) $o['parent_total'], 'sum' => 0.0, 'live' => 0];
                if (!$cancelled) {
                    $parents[$idOrder]['sum'] += $paid;
                    ++$parents[$idOrder]['live'];
                }
            }

            if ($cancelled) {
                ++$v['cancelled'];
                if ($ref !== '') {
                    $issues[] = $ctx + ['severity' => 'error', 'code' => 'cancelled_transferred', 'amount' => $payout,
                        'message' => sprintf('Cancelled order was paid out to the vendor (transfer %s)', $ref)];
                } elseif ($payout > self::TOLERANCE) {
                    $issues[] = $ctx + ['severity' => 'warning', 'code' => 'cancelled_with_payout', 'amount' => $payout,
                        'message' => sprintf('Cancelled order still carries a payout of %.2f', $payout)];
                }
                continue;
            }

            ++$v['orders'];
            $v['gross'] += $paid;
            $v['commission'] += $commission;
            $v['payout'] += $payout;
            if ($ref !== '') {
                $v['transferred'] += $payout;
            } else {
                $v['untransferred'] += $payout;
                $issues[] = $ctx + ['severity' => 'warning', 'code' => 'missing_transfer_ref', 'amount' => $payout,
                    'message' => 'No payment transfer reference for this vendor order'];
            }

            $sum = (float) $o['total_products'] + (float) $o['total_shipping'];
            if (abs($sum - $paid) > self::TOLERANCE) {
                $issues[] = $ctx + ['severity' => 'error', 'code' => 'total_mismatch', 'amount' => round($paid - $sum, 2),
                    'message' => sprintf('Products + shipping (%.2f) ≠ total paid (%.2f)', $sum, $paid)];
            }
            if (abs($paid - $commission - $payout) > self::TOLERANCE) {
                $issues[] = $ctx + ['severity' => 'error', 'code' => 'payout_mismatch', 'amount' => round($paid - $commission - $payout, 2),
                    'message' => sprintf('Total paid − commission (%.2f) ≠ vendor payout (%.2f)', $paid - $commission, $payout)];
            }
            if ($payout < -self::TOLERANCE) {
                $issues[] = $ctx + ['severity' => 'error', 'code' => 'negative_payout', 'amount' => $payout, 'message' => 'Negative vendor payout'];
            }
            if (isset($o['expected_commission']) && $o['expected_commission'] !== null
                && abs((float) $o['expected_commission'] - $commission) > self::TOLERANCE) {
                $issues[] = $ctx + ['severity' => 'warning', 'code' => 'commission_drift', 'amount' => round((float) $o['expected_commission'] - $commission, 2),
                    'message' => sprintf('Commission %.2f differs from current vendor terms (%.2f)', $commission, (float) $o['expected_commission'])];
            }
        }
        unset($v);

        foreach ($parents as $idOrder => $p) {
            if ($p['live'] > 0 && abs($p['parent'] - $p['sum']) > self::TOLERANCE) {
                $issues[] = ['severity' => 'warning', 'code' => 'parent_order_mismatch', 'id_order' => $idOrder, 'amount' => round($p['parent'] - $p['sum'], 2),
                    'message' => sprintf('PrestaShop order total %.2f ≠ Σ vendor orders %.2f', $p['parent'], $p['sum'])];
            }
        }

        foreach ($adjustments as $a) {
            $idVendor = (int) $a['id_vendor'];
            $vendors[$idVendor] ??= $blank;
            $vendors[$idVendor]['adjustments'] += (float) $a['amount'];
        }

        foreach ($settlements as $s) {
            if (!isset($s['computed'])) {
                continue;
            }
            $diffs = [];
            foreach (['gross', 'commission', 'adjustments', 'net_payout'] as $k) {
                if (abs((float) $s[$k] - (float) $s['computed'][$k]) > self::TOLERANCE) {
                    $diffs[] = sprintf('%s %.2f → %.2f', $k, (float) $s[$k], (float) $s['computed'][$k]);
                }
            }
            if ($diffs) {
                $locked = in_array($s['status'] ?? 'draft', ['approved', 'paid'], true);
                $issues[] = ['severity' => $locked ? 'error' : 'warning', 'code' => 'settlement_stale', 'id_vendor' => (int) $s['id_vendor'],
                    'id_settlement' => (int) $s['id_settlement'], 'amount' => round((float) $s['computed']['net_payout'] - (float) $s['net_payout'], 2),
                    'message' => sprintf('Settlement #%d (%s) differs from current data: %s', (int) $s['id_settlement'], $s['status'] ?? 'draft', implode(', ', $diffs))];
            }
        }

        $totals = $blank;
        foreach ($vendors as $idVendor => &$v) {
            $v['net_due'] = round($v['payout'] + $v['adjustments'], 2);
            foreach (['gross', 'commission', 'payout', 'adjustments', 'transferred', 'untransferred'] as $k) {
                $v[$k] = round($v[$k], 2);
            }
            foreach ($issues as $i) {
                if (($i['id_vendor'] ?? null) === $idVendor && $i['severity'] !== 'info') {
                    ++$v['issues'];
                }
            }
            foreach ($totals as $k => $_) {
                $totals[$k] += $v[$k];
            }
        }
        unset($v);
        foreach ($totals as $k => $val) {
            $totals[$k] = is_float($val) ? round($val, 2) : $val;
        }
        $totals['issues'] = count($issues);
        ksort($vendors);
        usort($issues, static fn ($a, $b) => [self::rank($a['severity']), $a['code']] <=> [self::rank($b['severity']), $b['code']]);

        return ['vendors' => $vendors, 'totals' => $totals, 'issues' => $issues];
    }

    private static function rank(string $severity): int
    {
        return ['error' => 0, 'warning' => 1, 'info' => 2][$severity] ?? 3;
    }
}
