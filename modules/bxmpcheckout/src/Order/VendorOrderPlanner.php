<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Order;

use BrainX\Marketplace\Domain\Commission\CommissionCalculator;
use BrainX\Marketplace\Domain\Commission\CommissionTerms;

/**
 * Pure: turns the persisted cart allocation (bxmp_cart_allocation rows + bxmp_cart_shipping) into one plan per vendor,
 * with commissions from the core CommissionCalculator and the vendor's commission terms.
 *
 * Reference of a sub-order: "<PARENTREF>-V<id_vendor>".
 */
final class VendorOrderPlanner
{
    public function __construct(private readonly CommissionCalculator $calculator = new CommissionCalculator())
    {
    }

    public static function reference(string $parentReference, int $idVendor): string
    {
        return $parentReference . '-V' . $idVendor;
    }

    /**
     * @param array<int, array{id_vendor:int|string,id_offer:int|string,id_product:int|string,id_product_attribute:int|string,quantity:int|string,unit_price:float|string,vendor_sku?:?string,product_name?:?string}> $allocationRows
     * @param array<int, float>          $shippingByVendor idVendor => shipping cost
     * @param array<int, CommissionTerms> $termsByVendor
     * @param array<int, array{name?:string,payout_account?:?string}> $vendorInfo
     *
     * @return VendorOrderPlan[] keyed by id_vendor, ascending
     */
    public function plan(string $parentReference, array $allocationRows, array $shippingByVendor, array $termsByVendor, array $vendorInfo = []): array
    {
        $byVendor = [];
        foreach ($allocationRows as $r) {
            $qty = (int) $r['quantity'];
            if ($qty <= 0) {
                continue;
            }
            $byVendor[(int) $r['id_vendor']][] = [
                'id_offer' => (int) $r['id_offer'],
                'id_product' => (int) $r['id_product'],
                'id_product_attribute' => (int) $r['id_product_attribute'],
                'product_name' => (string) ($r['product_name'] ?? ''),
                'vendor_sku' => isset($r['vendor_sku']) && $r['vendor_sku'] !== '' ? (string) $r['vendor_sku'] : null,
                'quantity' => $qty,
                'unit_price' => (float) $r['unit_price'],
            ];
        }
        ksort($byVendor);
        $plans = [];
        foreach ($byVendor as $idVendor => $lines) {
            $terms = $termsByVendor[$idVendor] ?? new CommissionTerms(0.0);
            $shipping = (float) ($shippingByVendor[$idVendor] ?? 0.0);
            $oc = $this->calculator->calculateOrder($lines, $shipping, $terms);
            foreach ($lines as $k => $l) {
                $lines[$k]['commission_amount'] = (float) $oc->lineCommissions[$k];
            }
            $plans[$idVendor] = new VendorOrderPlan(
                $idVendor,
                self::reference($parentReference, $idVendor),
                $lines,
                $oc->productsTotal,
                $oc->shippingTotal,
                $oc->commission,
                $vendorInfo[$idVendor]['name'] ?? null,
                $vendorInfo[$idVendor]['payout_account'] ?? null,
            );
        }

        return $plans;
    }

    /**
     * Compares the allocation with the products actually ordered (PS order details): returns the mismatches.
     *
     * @param array<int, array{id_product:int|string,id_product_attribute:int|string,quantity:int|string}> $allocationRows
     * @param array<string, int> $orderedQty "idProduct-idAttr" => quantity
     *
     * @return array<string, array{allocated:int, ordered:int}>
     */
    public static function quantityMismatches(array $allocationRows, array $orderedQty): array
    {
        $alloc = [];
        foreach ($allocationRows as $r) {
            $k = (int) $r['id_product'] . '-' . (int) $r['id_product_attribute'];
            $alloc[$k] = ($alloc[$k] ?? 0) + (int) $r['quantity'];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($alloc), array_keys($orderedQty))) as $k) {
            $a = $alloc[$k] ?? 0;
            $o = $orderedQty[$k] ?? 0;
            if ($a !== $o) {
                $out[$k] = ['allocated' => $a, 'ordered' => $o];
            }
        }

        return $out;
    }
}
