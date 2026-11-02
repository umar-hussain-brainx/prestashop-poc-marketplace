<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Order;

use BrainX\Marketplace\Domain\Commission\CommissionTerms;
use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\CartAllocationRepository;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\Marketplace\Infrastructure\Repository\VendorRepository;
use BrainX\Marketplace\Infrastructure\Service\CartOptimizationService;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitRequest;
use BrainX\MarketplaceCheckout\Payment\PaymentSplitter;
use BrainX\MarketplaceCheckout\Payment\VendorShare;

/**
 * actionValidateOrder: one customer payment / one PS parent order → one bxmp_vendor_order (+ lines) per vendor of the
 * cart allocation, commissions, vendor stock decrement, payment allocation (PaymentSplitter) and the
 * actionBxmpVendorOrderCreated hook. Idempotent per order.
 */
final class VendorOrderCreator
{
    public function __construct(
        private readonly PaymentSplitter $splitter,
        private readonly int $marketplaceCarrierId,
        private readonly CartAllocationRepository $allocations = new CartAllocationRepository(),
        private readonly VendorRepository $vendors = new VendorRepository(),
        private readonly OfferRepository $offers = new OfferRepository(),
        private readonly VendorOrderPlanner $planner = new VendorOrderPlanner(),
    ) {
    }

    /** @return int[] ids of the vendor orders of this PS order */
    public function createForOrder(\Order $order): array
    {
        $existing = \BxmpVendorOrder::getIdsByOrder((int) $order->id);
        if ($existing !== []) {
            return $existing;
        }
        $log = Logger::channel('checkout');
        $idCart = (int) $order->id_cart;

        $ordered = [];
        $names = [];
        foreach ($order->getProducts() as $d) {
            $k = (int) $d['product_id'] . '-' . (int) $d['product_attribute_id'];
            $ordered[$k] = ($ordered[$k] ?? 0) + (int) $d['product_quantity'];
            $names[$k] = (string) $d['product_name'];
        }
        if ($ordered === []) {
            return [];
        }

        $rows = array_values(array_filter($this->allocations->allocation($idCart), static fn (array $r) => isset($ordered[(int) $r['id_product'] . '-' . (int) $r['id_product_attribute']])));
        $mismatch = VendorOrderPlanner::quantityMismatches($rows, $ordered);
        $shipping = (int) $order->id_carrier === $this->marketplaceCarrierId ? $this->allocations->shipping($idCart) : [];
        if ($mismatch !== []) {
            // Allocation is stale or missing (e.g. order created outside the storefront): re-optimise what was ordered.
            $log->warning('Cart allocation does not match the order, re-optimising', ['id_order' => (int) $order->id, 'mismatch' => $mismatch]);
            $basket = [];
            foreach ($ordered as $k => $q) {
                [$p, $a] = array_map('intval', explode('-', $k));
                $basket[] = new BasketLine($p, $a, $q);
            }
            $service = new CartOptimizationService();
            $result = $service->optimize($basket, CartOptimizationService::destinationFromAddress((int) $order->id_address_delivery));
            $rows = [];
            foreach ($result->lines() as $l) {
                $rows[] = ['id_vendor' => $l->idVendor, 'id_offer' => $l->idOffer, 'id_product' => $l->idProduct, 'id_product_attribute' => $l->idProductAttribute, 'quantity' => $l->quantity, 'unit_price' => $l->unitPrice];
            }
            $shipping = [];
            foreach ($result->vendors as $v) {
                $shipping[$v->idVendor] = $v->shippingCost;
            }
        }

        // enrich rows: product name (as ordered) + vendor SKU
        $skus = $this->vendorSkus(array_map(static fn ($r) => (int) $r['id_offer'], $rows));
        foreach ($rows as &$r) {
            $k = (int) $r['id_product'] . '-' . (int) $r['id_product_attribute'];
            $r['product_name'] = $names[$k] ?? '';
            $r['vendor_sku'] = $skus[(int) $r['id_offer']] ?? null;
        }
        unset($r);

        $vendorIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['id_vendor'], $rows)));
        $vendorRows = $this->vendors->findByIds($vendorIds);
        $applyOnShipping = (bool) \Configuration::get('BXMP_COMMISSION_ON_SHIPPING');
        $terms = [];
        $info = [];
        foreach ($vendorRows as $id => $v) {
            $terms[$id] = CommissionTerms::fromVendorRow($v, $applyOnShipping);
            $info[$id] = ['name' => (string) $v['name'], 'payout_account' => $v['payout_account'] ?: null];
        }

        $plans = $this->planner->plan((string) $order->reference, $rows, $shipping, $terms, $info);
        $now = date('Y-m-d H:i:s');
        $created = [];
        $shares = [];
        foreach ($plans as $idVendor => $plan) {
            $vo = new \BxmpVendorOrder();
            $vo->id_order = (int) $order->id;
            $vo->id_vendor = $idVendor;
            $vo->reference = $plan->reference;
            $vo->total_products = $plan->totalProducts;
            $vo->total_shipping = $plan->totalShipping;
            $vo->total_paid = $plan->totalPaid();
            $vo->commission_amount = $plan->commission;
            $vo->vendor_payout = $plan->vendorPayout();
            $vo->status = 'pending';
            $vo->date_add = $now;
            $vo->date_upd = $now;
            if (!$vo->add()) {
                $log->error('Could not create vendor order', ['id_order' => (int) $order->id, 'id_vendor' => $idVendor]);
                continue;
            }
            foreach ($plan->lines as $l) {
                $line = new \BxmpVendorOrderLine();
                $line->id_vendor_order = (int) $vo->id;
                $line->id_offer = $l['id_offer'];
                $line->id_product = $l['id_product'];
                $line->id_product_attribute = $l['id_product_attribute'];
                $line->product_name = mb_substr($l['product_name'], 0, 255);
                $line->vendor_sku = $l['vendor_sku'] !== null ? mb_substr($l['vendor_sku'], 0, 64) : null;
                $line->quantity = $l['quantity'];
                $line->unit_price = $l['unit_price'];
                $line->commission_amount = $l['commission_amount'];
                $line->add();
                if ($l['id_offer'] > 0) {
                    $this->offers->decrementStock($l['id_offer'], $l['quantity']);
                }
            }
            $created[$idVendor] = $vo;
            $shares[] = new VendorShare($idVendor, (int) $vo->id, $plan->reference, $plan->walletId, $plan->totalPaid(), $plan->commission, $plan->vendorName);
        }

        $sumProducts = array_sum(array_map(static fn ($p) => $p->totalProducts, $plans));
        if (abs($sumProducts - (float) $order->total_products) > 0.011) {
            $log->warning('Vendor split products total differs from the PS order (rounding of blended prices?)', [
                'id_order' => (int) $order->id, 'vendors' => round($sumProducts, 2), 'order_total_products' => (float) $order->total_products]);
        }

        $this->allocatePayment($order, $shares, $created);

        foreach ($created as $idVendor => $vo) {
            \Hook::exec('actionBxmpVendorOrderCreated', ['id_vendor_order' => (int) $vo->id, 'id_order' => (int) $order->id, 'id_vendor' => $idVendor]);
        }
        $log->info('Order split into vendor orders', ['id_order' => (int) $order->id, 'reference' => $order->reference,
            'vendor_orders' => array_map(static fn ($vo) => ['id' => (int) $vo->id, 'ref' => $vo->reference, 'paid' => (float) $vo->total_paid, 'commission' => (float) $vo->commission_amount], $created)]);

        return array_values(array_map(static fn ($vo) => (int) $vo->id, $created));
    }

    /** @param VendorShare[] $shares @param array<int, \BxmpVendorOrder> $created */
    private function allocatePayment(\Order $order, array $shares, array $created): void
    {
        if ($shares === []) {
            return;
        }
        $currency = new \Currency((int) $order->id_currency);
        $request = new PaymentSplitRequest((int) $order->id, (string) $order->reference, (int) $order->id_customer,
            (string) ($currency->iso_code ?: 'EUR'), (float) $order->total_paid_tax_incl, (string) $order->module, $shares);
        $result = $this->splitter->split($request);
        $level = $result->isOk() ? 'info' : 'error';
        Logger::write('payment', $level, sprintf('Payment split %s for order %s: %s', $result->status, $order->reference, $result->message), [
            'id_order' => (int) $order->id, 'provider' => $result->provider, 'payin' => $result->payInRef, 'transfers' => $result->transferRefs, 'payloads' => $result->payloads]);
        foreach ($created as $idVendor => $vo) {
            $ref = $result->referenceFor($idVendor);
            if ($ref !== null) {
                $vo->payment_transfer_ref = $ref;
                $vo->update();
            }
        }
    }

    /** @param int[] $offerIds @return array<int, string> */
    private function vendorSkus(array $offerIds): array
    {
        $offerIds = array_filter(array_unique($offerIds));
        if ($offerIds === []) {
            return [];
        }
        $out = [];
        foreach (\Db::getInstance()->executeS('SELECT id_offer, vendor_sku FROM `' . _DB_PREFIX_ . 'bxmp_offer` WHERE id_offer IN (' . implode(',', array_map('intval', $offerIds)) . ')') ?: [] as $r) {
            $out[(int) $r['id_offer']] = (string) $r['vendor_sku'];
        }

        return $out;
    }
}
