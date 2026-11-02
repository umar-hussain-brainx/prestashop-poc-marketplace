<?php

declare(strict_types=1);

namespace BrainX\MarketplaceVendor\Domain;

/**
 * Validation of offer edits from the vendor portal (price, discount price + window, quantity, active)
 * and parsing of the bulk stock textarea.
 */
final class OfferInput
{
    public const MAX_PRICE = 1000000.0;
    public const MAX_QTY = 1000000;

    /**
     * @param array<string, mixed> $in
     *
     * @return array{errors: string[], data: array<string, mixed>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $num = static function ($v): ?float {
            $v = trim(str_replace(',', '.', (string) $v));

            return ($v === '' || !is_numeric($v)) ? null : (float) $v;
        };
        $price = $num($in['price'] ?? '');
        if ($price === null || $price <= 0 || $price > self::MAX_PRICE) {
            $errors[] = 'Price must be a positive amount.';
            $priceValid = false;
        } else {
            $priceValid = true;
        }
        $discountRaw = trim((string) ($in['discount_price'] ?? ''));
        $discount = null;
        if ($discountRaw !== '') {
            $discount = $num($discountRaw);
            if ($discount === null || $discount <= 0) {
                $errors[] = 'Discount price must be a positive amount (or empty).';
                $discount = null;
            } elseif ($priceValid && $discount >= $price) {
                $errors[] = 'Discount price must be lower than the price.';
            }
        }
        $from = self::dateTime($in['discount_from'] ?? '');
        $to = self::dateTime($in['discount_to'] ?? '');
        if ($from === false || $to === false) {
            $errors[] = 'Invalid discount date (use YYYY-MM-DD or YYYY-MM-DD HH:MM).';
            $from = $from ?: null;
            $to = $to ?: null;
        }
        if ($from !== null && $to !== null && $from > $to) {
            $errors[] = 'Discount start must be before its end.';
        }
        if ($discount === null) {
            $from = $to = null;
        }
        $qtyRaw = trim((string) ($in['quantity'] ?? '0'));
        if (!preg_match('/^\d{1,7}$/', $qtyRaw) || (int) $qtyRaw > self::MAX_QTY) {
            $errors[] = 'Quantity must be a whole number ≥ 0.';
        }

        return ['errors' => $errors, 'data' => [
            'price' => $price === null ? null : round($price, 6),
            'discount_price' => $discount === null ? null : round($discount, 6),
            'discount_from' => $from,
            'discount_to' => $to,
            'quantity' => (int) $qtyRaw,
            'active' => !empty($in['active']),
        ]];
    }

    /** @return string|null|false 'Y-m-d H:i:s', null when empty, false when invalid */
    public static function dateTime($raw): string|null|false
    {
        $raw = trim(str_replace('T', ' ', (string) $raw));
        if ($raw === '') {
            return null;
        }
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $raw);
            $errs = \DateTimeImmutable::getLastErrors();
            if ($d !== false && ($errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0))) {
                return $d->format('Y-m-d H:i:s');
            }
        }

        return false;
    }

    /**
     * Parses bulk stock lines "SKU-or-EAN;quantity" (separators ; , tab or space). Blank lines and # comments ignored.
     *
     * @return array{items: array<string,int>, errors: string[]} key => quantity (last wins)
     */
    public static function parseBulkStock(string $text, int $maxLines = 2000): array
    {
        $items = [];
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (count($items) >= $maxLines) {
                $errors[] = sprintf('Too many lines (max %d).', $maxLines);
                break;
            }
            if (!preg_match('/^([A-Za-z0-9._\/-]{1,64})\s*[;,\t ]\s*(\d{1,7})$/', $line, $m)) {
                $errors[] = sprintf('Line %d: expected "SKU or EAN;quantity".', $i + 1);
                continue;
            }
            $items[$m[1]] = (int) $m[2];
        }

        return ['items' => $items, 'errors' => $errors];
    }
}
