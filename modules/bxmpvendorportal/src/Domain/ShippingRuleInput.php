<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

/**
 * Validation / normalisation of a vendor shipping rule submitted from the portal
 * (semantics: see core ShippingRuleResolver — zip pattern = comma list of prefixes, ranges "00100-00199" or "*").
 */
final class ShippingRuleInput
{
    /**
     * @param array<string, mixed> $in        raw form values
     * @param int[]                $countryIds valid country ids (0 = all countries is always accepted)
     *
     * @return array{errors: string[], data: array<string, mixed>}
     */
    public static function validate(array $in, array $countryIds = []): array
    {
        $errors = [];
        $idCountry = (int) ($in['id_country'] ?? 0);
        if ($idCountry < 0 || ($idCountry > 0 && $countryIds !== [] && !in_array($idCountry, $countryIds, true))) {
            $errors[] = 'Invalid country.';
        }
        $pattern = self::normalizePattern((string) ($in['zipcode_pattern'] ?? ''));
        if ($pattern === false) {
            $errors[] = 'Invalid postcode pattern: use a comma separated list of prefixes (20,21), ranges (00100-00199) or *.';
            $pattern = null;
        }
        $excluded = !empty($in['excluded']);
        $money = static function (string $field, bool $nullable) use ($in, &$errors): ?float {
            $raw = trim(str_replace(',', '.', (string) ($in[$field] ?? '')));
            if ($raw === '') {
                return $nullable ? null : 0.0;
            }
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1000000) {
                $errors[] = sprintf('Invalid amount for %s.', str_replace('_', ' ', $field));

                return null;
            }

            return round((float) $raw, 6);
        };
        $base = $money('base_cost', false) ?? 0.0;
        $free = $money('free_threshold', true);
        $reducedThreshold = $money('reduced_threshold', true);
        $reducedCost = $money('reduced_cost', true);
        if (($reducedThreshold === null) !== ($reducedCost === null)) {
            $errors[] = 'Reduced threshold and reduced cost must be set together.';
        }
        if ($reducedThreshold !== null && $free !== null && $reducedThreshold >= $free) {
            $errors[] = 'Reduced threshold must be lower than the free-shipping threshold.';
        }
        if ($reducedCost !== null && $reducedCost > $base) {
            $errors[] = 'Reduced cost cannot exceed the base cost.';
        }
        $priority = (int) ($in['priority'] ?? 0);
        if ($priority < -1000 || $priority > 1000) {
            $errors[] = 'Priority must be between -1000 and 1000.';
        }
        if ($excluded) {
            // an exclusion rule carries no cost
            $base = 0.0;
            $free = $reducedThreshold = $reducedCost = null;
        }

        return ['errors' => $errors, 'data' => [
            'id_country' => $idCountry,
            'zipcode_pattern' => $pattern,
            'excluded' => $excluded,
            'base_cost' => $base,
            'free_threshold' => $free,
            'reduced_threshold' => $reducedThreshold,
            'reduced_cost' => $reducedCost,
            'priority' => $priority,
        ]];
    }

    /** @return string|null|false normalised pattern, null for "all postcodes", false when invalid */
    public static function normalizePattern(string $raw): string|null|false
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $items = [];
        foreach (explode(',', $raw) as $item) {
            $item = strtoupper(str_replace(' ', '', $item));
            if ($item === '') {
                continue;
            }
            if ($item === '*' || preg_match('/^[0-9A-Z]{1,10}$/', $item)) {
                $items[] = $item;
                continue;
            }
            if (preg_match('/^([0-9A-Z]{1,10})-([0-9A-Z]{1,10})$/', $item, $m)) {
                $len = max(strlen($m[1]), strlen($m[2]));
                if (strcmp(str_pad($m[1], $len, '0', STR_PAD_LEFT), str_pad($m[2], $len, '0', STR_PAD_LEFT)) > 0) {
                    return false;
                }
                $items[] = $item;
                continue;
            }

            return false;
        }
        if ($items === []) {
            return null;
        }
        $out = implode(',', array_values(array_unique($items)));

        return strlen($out) > 255 ? false : $out;
    }
}
