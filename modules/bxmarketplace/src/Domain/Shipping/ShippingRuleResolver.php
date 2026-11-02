<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Domain\Shipping;

use BrainX\Marketplace\Domain\Optimizer\Destination;

/**
 * Picks the applicable vendor shipping rule for a destination and prices a vendor sub-order.
 *
 * Rule selection: candidate rules are those whose country is 0 (all) or equals the destination country AND whose
 * zipcode_pattern is null or matches the postcode. The winner is the highest `priority`; ties are broken by
 * specificity (zip pattern > country-specific > catch-all), then lowest id. If the winner is `excluded`, the vendor
 * does not deliver there.
 *
 * Vendors with NO rules at all are treated as "delivers everywhere, free shipping" (see README).
 * Vendors that HAVE rules but none applies to the destination do not deliver there.
 *
 * Cost for a vendor sub-total S (tax excl.): 0 if S == 0 (vendor unused); 0 if free_threshold set and S >= it;
 * reduced_cost if reduced_threshold set and S >= it; base_cost otherwise. The cost is non-increasing in S.
 */
final class ShippingRuleResolver
{
    public const EPSILON = 0.000001;

    public const TIER_NONE = 'none';
    public const TIER_BASE = 'base';
    public const TIER_REDUCED = 'reduced';
    public const TIER_FREE = 'free';

    /**
     * @param ShippingRule[] $rules rules of ONE vendor
     *
     * @return ShippingRule|null the applicable rule (may be an `excluded` rule), null if no rule applies
     */
    public function resolve(array $rules, Destination $destination): ?ShippingRule
    {
        $best = null;
        $bestKey = null;
        foreach ($rules as $rule) {
            if ($rule->countryId !== 0 && $rule->countryId !== $destination->countryId) {
                continue;
            }
            if ($rule->zipcodePattern !== null && !self::zipMatches($rule->zipcodePattern, $destination->postcode)) {
                continue;
            }
            $specificity = ($rule->zipcodePattern !== null ? 2 : 0) + ($rule->countryId !== 0 ? 1 : 0);
            $key = [$rule->priority, $specificity, -$rule->id];
            if ($bestKey === null || $key > $bestKey) {
                $best = $rule;
                $bestKey = $key;
            }
        }

        return $best;
    }

    /** @param ShippingRule[] $rules */
    public function canDeliver(array $rules, Destination $destination): bool
    {
        if ($rules === []) {
            return true;
        }
        $rule = $this->resolve($rules, $destination);

        return $rule !== null && !$rule->excluded;
    }

    /**
     * Effective (non-excluded) rule for the destination, a zero-cost rule when the vendor has no rules,
     * or null when the vendor cannot deliver.
     *
     * @param ShippingRule[] $rules
     */
    public function effectiveRule(array $rules, Destination $destination, int $vendorId = 0): ?ShippingRule
    {
        if ($rules === []) {
            return new ShippingRule($vendorId, 0.0);
        }
        $rule = $this->resolve($rules, $destination);

        return ($rule === null || $rule->excluded) ? null : $rule;
    }

    public static function cost(ShippingRule $rule, float $subtotal): float
    {
        return match (self::tier($rule, $subtotal)) {
            self::TIER_NONE, self::TIER_FREE => 0.0,
            self::TIER_REDUCED => (float) $rule->reducedCost,
            default => $rule->baseCost,
        };
    }

    public static function tier(ShippingRule $rule, float $subtotal): string
    {
        if ($subtotal <= self::EPSILON) {
            return self::TIER_NONE;
        }
        if ($rule->freeThreshold !== null && $subtotal + self::EPSILON >= $rule->freeThreshold) {
            return self::TIER_FREE;
        }
        if ($rule->reducedThreshold !== null && $rule->reducedCost !== null && $subtotal + self::EPSILON >= $rule->reducedThreshold
            && $rule->reducedCost < $rule->baseCost) {
            return self::TIER_REDUCED;
        }

        return $rule->baseCost <= self::EPSILON ? self::TIER_FREE : self::TIER_BASE;
    }

    /**
     * Shipping cost of a vendor sub-order, or null when the vendor does not deliver to the destination.
     *
     * @param ShippingRule[] $rules
     */
    public function costFor(array $rules, Destination $destination, float $subtotal): ?float
    {
        $rule = $this->effectiveRule($rules, $destination);

        return $rule === null ? null : self::cost($rule, $subtotal);
    }

    /**
     * Zip pattern: comma separated list of prefixes ("20", "09") and inclusive ranges ("00100-00199").
     * A range compares the first N characters of the postcode (N = bound length) numerically/lexically.
     * "*" matches everything. Matching is case-insensitive and ignores spaces.
     */
    public static function zipMatches(string $pattern, string $postcode): bool
    {
        $zip = strtoupper(str_replace([' ', '-'], '', $postcode));
        if ($zip === '') {
            return false;
        }
        foreach (explode(',', $pattern) as $item) {
            $item = strtoupper(str_replace(' ', '', $item));
            if ($item === '') {
                continue;
            }
            if ($item === '*') {
                return true;
            }
            if (preg_match('/^([0-9A-Z]+)-([0-9A-Z]+)$/', $item, $m)) {
                $len = max(strlen($m[1]), strlen($m[2]));
                $head = substr($zip, 0, $len);
                if (strlen($head) < $len) {
                    continue;
                }
                $lo = str_pad($m[1], $len, '0', STR_PAD_LEFT);
                $hi = str_pad($m[2], $len, '0', STR_PAD_LEFT);
                if (strcmp($head, $lo) >= 0 && strcmp($head, $hi) <= 0) {
                    return true;
                }
                continue;
            }
            if (str_starts_with($zip, $item)) {
                return true;
            }
        }

        return false;
    }
}
