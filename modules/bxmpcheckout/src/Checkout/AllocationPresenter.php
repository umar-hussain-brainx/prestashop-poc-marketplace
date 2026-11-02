<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Checkout;

use BrainX\Marketplace\Domain\Optimizer\AllocationResult;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use BrainX\Marketplace\Domain\Shipping\ShippingRuleResolver;

/**
 * Pure: view model of an optimizer result for the storefront ("Optimized allocation" block): vendor sub-baskets,
 * shipping tier + next-threshold hint ("add €X for free shipping from Vendor B"), alternatives and savings.
 */
final class AllocationPresenter
{
    /** @var callable(float):string */
    private $money;

    public function __construct(?callable $money = null, private readonly ShippingRuleResolver $resolver = new ShippingRuleResolver())
    {
        $this->money = $money ?? static fn (float $v): string => '€' . number_format($v, 2, '.', ',');
    }

    /**
     * @param array<int, ShippingPolicy> $policies     any keys, matched on idVendor
     * @param array<string, string>      $productNames "idProduct-idAttr" => display name
     */
    public function present(AllocationResult $result, array $policies, Destination $destination, array $productNames = []): array
    {
        $byVendor = [];
        foreach ($policies as $p) {
            $byVendor[$p->idVendor] = $p;
        }
        $vendors = [];
        foreach ($result->vendors as $va) {
            $lines = [];
            foreach ($va->lines as $l) {
                $k = $l->idProduct . '-' . $l->idProductAttribute;
                $lines[] = [
                    'id_product' => $l->idProduct,
                    'id_product_attribute' => $l->idProductAttribute,
                    'name' => $productNames[$k] ?? ('Product #' . $l->idProduct),
                    'quantity' => $l->quantity,
                    'unit_price' => round($l->unitPrice, 2),
                    'unit_price_fmt' => $this->fmt($l->unitPrice),
                    'total' => round($l->total(), 2),
                    'total_fmt' => $this->fmt($l->total()),
                ];
            }
            $policy = $byVendor[$va->idVendor] ?? null;
            $rule = $this->resolver->effectiveRule($policy ? $policy->rules : [], $destination, $va->idVendor);
            $vendors[] = [
                'id_vendor' => $va->idVendor,
                'name' => $va->vendorName ?? ($policy?->vendorName ?? 'Vendor #' . $va->idVendor),
                'lines' => $lines,
                'subtotal' => round($va->subtotal, 2),
                'subtotal_fmt' => $this->fmt($va->subtotal),
                'shipping' => round($va->shippingCost, 2),
                'shipping_fmt' => $va->shippingCost <= 0.000001 ? 'Free' : $this->fmt($va->shippingCost),
                'tier' => $va->shippingTier,
                'total' => round($va->total(), 2),
                'total_fmt' => $this->fmt($va->total()),
                'rule_summary' => $rule ? self::ruleSummary($rule, $this->money) : null,
                'hint' => $rule ? $this->hint($rule, $va->subtotal, $va->shippingCost, $va->vendorName ?? 'this vendor') : null,
            ];
        }

        $total = $result->total();
        $alternatives = [];
        $bestSingle = null;
        $cheapest = null;
        foreach ($result->alternatives as $a) {
            $selected = str_contains($a['label'], '(selected)');
            $t = $a['total'];
            $alternatives[] = [
                'label' => $a['label'],
                'feasible' => (bool) $a['feasible'],
                'total' => $t === null ? null : round($t, 2),
                'total_fmt' => $t === null ? 'n/a' : $this->fmt($t),
                'note' => $a['note'] ?? '',
                'selected' => $selected,
                'extra' => ($t === null || $selected) ? null : round($t - $total, 2),
                'extra_fmt' => ($t === null || $selected) ? '' : ($t - $total > 0.004 ? '+' . $this->fmt($t - $total) : '±' . $this->fmt(0)),
            ];
            if ($selected || $t === null) {
                continue;
            }
            if (str_starts_with($a['label'], 'Cheapest offer')) {
                $cheapest = $t;
            } elseif ($bestSingle === null || $t < $bestSingle['total']) {
                $bestSingle = ['label' => $a['label'], 'total' => $t];
            }
        }
        $savings = [];
        if ($cheapest !== null && $cheapest - $total > 0.004) {
            $savings[] = ['label' => 'vs. cheapest offer per item', 'amount' => round($cheapest - $total, 2), 'amount_fmt' => $this->fmt($cheapest - $total)];
        }
        if ($bestSingle !== null && $bestSingle['total'] - $total > 0.004) {
            $savings[] = ['label' => 'vs. best single-vendor order (' . preg_replace('/^Everything from /', '', $bestSingle['label']) . ')',
                'amount' => round($bestSingle['total'] - $total, 2), 'amount_fmt' => $this->fmt($bestSingle['total'] - $total)];
        }

        return [
            'vendors' => $vendors,
            'vendor_count' => count($vendors),
            'items_total' => round($result->itemsTotal, 2),
            'items_total_fmt' => $this->fmt($result->itemsTotal),
            'shipping_total' => round($result->shippingTotal, 2),
            'shipping_total_fmt' => $result->shippingTotal <= 0.000001 ? 'Free' : $this->fmt($result->shippingTotal),
            'total' => round($total, 2),
            'total_fmt' => $this->fmt($total),
            'alternatives' => $alternatives,
            'savings' => $savings,
            'mode' => $result->mode,
            'proven_optimal' => $result->provenOptimal,
            'explanation' => $result->explanation,
            'split_lines' => self::splitLines($result),
        ];
    }

    /** Next shipping tier the vendor sub-basket could reach (null when already free / no threshold). */
    public function hint(ShippingRule $rule, float $subtotal, float $currentCost, string $vendorName): ?array
    {
        if ($currentCost <= 0.000001) {
            return null;
        }
        $target = null;
        if ($rule->reducedThreshold !== null && $rule->reducedCost !== null && $rule->reducedCost < $currentCost - 0.000001
            && $subtotal < $rule->reducedThreshold && ($rule->freeThreshold === null || $rule->reducedThreshold < $rule->freeThreshold)) {
            $target = ['type' => 'reduced', 'threshold' => $rule->reducedThreshold, 'cost' => (float) $rule->reducedCost];
        } elseif ($rule->freeThreshold !== null && $subtotal < $rule->freeThreshold) {
            $target = ['type' => 'free', 'threshold' => $rule->freeThreshold, 'cost' => 0.0];
        }
        if ($target === null) {
            return null;
        }
        $missing = $target['threshold'] - $subtotal;
        $text = $target['type'] === 'free'
            ? sprintf('Add %s more from %s for free shipping', $this->fmt($missing), $vendorName)
            : sprintf('Add %s more from %s for reduced shipping (%s)', $this->fmt($missing), $vendorName, $this->fmt($target['cost']));

        return [
            'type' => $target['type'],
            'threshold' => round($target['threshold'], 2),
            'missing' => round($missing, 2),
            'missing_fmt' => $this->fmt($missing),
            'saves' => round($currentCost - $target['cost'], 2),
            'progress' => (int) max(0, min(100, floor($subtotal / max(0.01, $target['threshold']) * 100))),
            'text' => $text,
        ];
    }

    /** Human summary of a shipping rule, e.g. "€5.00 · free from €30.00". */
    public static function ruleSummary(ShippingRule $rule, ?callable $money = null): string
    {
        $money ??= static fn (float $v): string => '€' . number_format($v, 2, '.', ',');
        if ($rule->baseCost <= 0.000001) {
            return 'Free shipping';
        }
        $parts = [$money($rule->baseCost)];
        if ($rule->reducedThreshold !== null && $rule->reducedCost !== null && $rule->reducedCost < $rule->baseCost) {
            $parts[] = $money((float) $rule->reducedCost) . ' from ' . $money($rule->reducedThreshold);
        }
        if ($rule->freeThreshold !== null) {
            $parts[] = 'free from ' . $money($rule->freeThreshold);
        }

        return implode(' · ', $parts);
    }

    /** Products whose quantity is split across several vendors: "idP-idA" => vendor count. */
    public static function splitLines(AllocationResult $result): array
    {
        $vendors = [];
        foreach ($result->lines() as $l) {
            $vendors[$l->idProduct . '-' . $l->idProductAttribute][$l->idVendor] = true;
        }

        return array_map('count', array_filter($vendors, static fn (array $v) => count($v) > 1));
    }

    private function fmt(float $v): string
    {
        return ($this->money)($v);
    }
}
