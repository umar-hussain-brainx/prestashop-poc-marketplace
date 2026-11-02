<?php

declare(strict_types=1);

namespace BrainX\Csv\Validation;

use BrainX\Csv\Parser\NumberParser;

/**
 * Strict validation + normalisation of one offer CSV record (canonical columns, see OfferSchema).
 *
 * Produces typed data:
 *   vendor_sku string, ean ?string (13 digits), reference ?string, brand ?string, title string, description string,
 *   category ?string, price float, discount_price ?float, discount_from ?string, discount_to ?string, quantity int,
 *   images string[], condition string, variant_attributes array<string,string>, parent_reference ?string, active bool
 */
final class OfferRowValidator
{
    public const CONDITIONS = [
        'new' => 'new', 'nuovo' => 'new', 'neuf' => 'new', 'neu' => 'new', 'nuevo' => 'new',
        'used' => 'used', 'usato' => 'used', 'occasion' => 'used', 'second_hand' => 'used', 'secondhand' => 'used',
        'refurbished' => 'refurbished', 'refurb' => 'refurbished', 'ricondizionato' => 'refurbished', 'reconditionne' => 'refurbished',
    ];

    public const MAX_PRICE = 9999999.0;
    public const MAX_IMAGES = 10;

    public function __construct(private readonly ?string $decimalHint = null)
    {
    }

    /** @param array<string, string> $raw canonical column => raw string */
    public function validate(array $raw, int $line = 0): RowResult
    {
        $e = [];
        $w = [];
        $d = [];
        $v = static fn (string $k): string => trim((string) ($raw[$k] ?? ''));

        // vendor_sku
        $sku = $v('vendor_sku');
        if ($sku === '') {
            $e[] = 'vendor_sku: required';
        } elseif (mb_strlen($sku) > 64) {
            $e[] = 'vendor_sku: longer than 64 characters';
        } elseif (!self::isReference($sku)) {
            $e[] = 'vendor_sku: contains forbidden characters (< > ; = { })';
        }
        $d['vendor_sku'] = $sku;

        // ean
        $d['ean'] = null;
        $eanRaw = $v('ean');
        if ($eanRaw !== '') {
            if (preg_match('/^\d+([.,]\d+)?E\+?\d+$/i', $eanRaw)) {
                $e[] = 'ean: "' . $eanRaw . '" is in scientific notation (format the column as text in your spreadsheet)';
            } else {
                $digits = preg_replace('/[\s\-.]/', '', $eanRaw) ?? '';
                if (!ctype_digit($digits)) {
                    $e[] = 'ean: "' . $eanRaw . '" must contain digits only';
                } else {
                    if (strlen($digits) === 14 && $digits[0] === '0') {
                        $digits = substr($digits, 1);
                    }
                    if (strlen($digits) === 12) {
                        $digits = '0' . $digits;
                    }
                    if (strlen($digits) === 8) {
                        $w[] = 'ean: EAN-8 "' . $eanRaw . '" is not used for matching';
                    } elseif (strlen($digits) !== 13) {
                        $e[] = 'ean: "' . $eanRaw . '" must have 12 (UPC-A) or 13 digits';
                    } else {
                        if (!self::isValidEan13($digits)) {
                            $w[] = 'ean: ' . $digits . ' has an invalid GS1 check digit';
                        }
                        $d['ean'] = $digits;
                    }
                }
            }
        }

        // reference / MPN
        $ref = $v('reference');
        if ($ref !== '' && (mb_strlen($ref) > 64 || !self::isReference($ref))) {
            $e[] = 'reference: invalid (max 64 chars, no < > ; = { })';
        }
        $d['reference'] = $ref === '' ? null : $ref;

        $parent = $v('parent_reference');
        if ($parent !== '' && (mb_strlen($parent) > 64 || !self::isReference($parent))) {
            $e[] = 'parent_reference: invalid (max 64 chars, no < > ; = { })';
        }
        $d['parent_reference'] = $parent === '' ? null : $parent;

        // brand
        $brand = $v('brand');
        if (mb_strlen($brand) > 64) {
            $e[] = 'brand: longer than 64 characters';
        } elseif ($brand !== '' && preg_match('/[<>;=#{}]/u', $brand)) {
            $e[] = 'brand: contains forbidden characters (< > ; = # { })';
        }
        $d['brand'] = $brand === '' ? null : $brand;

        // title
        $title = preg_replace('/\s+/u', ' ', $v('title')) ?? '';
        if (preg_match('/[<>;=#{}]/u', $title)) {
            $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/[<>;=#{}]/u', ' ', $title) ?? '') ?? '');
            $w[] = 'title: forbidden characters (< > ; = # { }) replaced by spaces';
        }
        if (mb_strlen($title) > 128) {
            $title = rtrim(mb_substr($title, 0, 128));
            $w[] = 'title: truncated to 128 characters';
        }
        $d['title'] = $title;

        $d['description'] = self::cleanHtml($v('description'));
        $cat = $v('category');
        if (mb_strlen($cat) > 255) {
            $e[] = 'category: longer than 255 characters';
        }
        $d['category'] = $cat === '' ? null : $cat;

        if ($d['ean'] === null && $d['reference'] === null && $title === '') {
            $e[] = 'row: no product identifier (ean, reference or title required)';
        }

        // price
        $d['price'] = null;
        $priceRaw = $v('price');
        if ($priceRaw === '') {
            $e[] = 'price: required';
        } else {
            $p = NumberParser::parse($priceRaw, $this->decimalHint);
            if ($p === null) {
                $e[] = 'price: "' . $priceRaw . '" is not a valid number';
            } elseif ($p <= 0) {
                $e[] = 'price: must be greater than 0';
            } elseif ($p > self::MAX_PRICE) {
                $e[] = 'price: too large';
            } else {
                $d['price'] = round($p, 6);
            }
        }

        // discount
        $d['discount_price'] = null;
        $dpRaw = $v('discount_price');
        if ($dpRaw !== '') {
            $dp = NumberParser::parse($dpRaw, $this->decimalHint);
            if ($dp === null) {
                $e[] = 'discount_price: "' . $dpRaw . '" is not a valid number';
            } elseif ($dp <= 0) {
                $e[] = 'discount_price: must be greater than 0';
            } elseif ($d['price'] !== null && $dp >= $d['price']) {
                $e[] = 'discount_price: must be lower than price';
            } else {
                $d['discount_price'] = round($dp, 6);
            }
        }
        $d['discount_from'] = null;
        $d['discount_to'] = null;
        foreach (['discount_from' => false, 'discount_to' => true] as $col => $endOfDay) {
            $raw_ = $v($col);
            if ($raw_ === '') {
                continue;
            }
            $date = DateParser::parse($raw_, $endOfDay);
            if ($date === null) {
                $e[] = $col . ': "' . $raw_ . '" is not a valid date (YYYY-MM-DD[ HH:MM[:SS]] or DD/MM/YYYY)';
            } else {
                $d[$col] = $date;
            }
        }
        if ($d['discount_from'] !== null && $d['discount_to'] !== null && $d['discount_from'] > $d['discount_to']) {
            $e[] = 'discount_to: must be after discount_from';
        }
        if ($dpRaw === '' && ($d['discount_from'] !== null || $d['discount_to'] !== null)) {
            $w[] = 'discount dates ignored: no discount_price';
            $d['discount_from'] = $d['discount_to'] = null;
        }

        // quantity
        $d['quantity'] = 0;
        $qRaw = $v('quantity');
        if ($qRaw === '') {
            $e[] = 'quantity: required';
        } else {
            $q = NumberParser::parseInt($qRaw, $this->decimalHint);
            if ($q === null) {
                $e[] = 'quantity: "' . $qRaw . '" is not a whole number';
            } elseif ($q < 0) {
                $w[] = 'quantity: negative stock set to 0';
            } elseif ($q > 9999999) {
                $e[] = 'quantity: too large';
            } else {
                $d['quantity'] = $q;
            }
        }

        // images
        $d['images'] = [];
        $imgRaw = $v('images');
        if ($imgRaw !== '') {
            foreach (array_filter(array_map('trim', explode('|', $imgRaw)), 'strlen') as $url) {
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true) || filter_var(str_replace(' ', '%20', $url), FILTER_VALIDATE_URL) === false) {
                    $w[] = 'images: "' . mb_substr($url, 0, 80) . '" is not an http(s) URL, ignored';
                    continue;
                }
                $d['images'][] = $url;
            }
            if (count($d['images']) > self::MAX_IMAGES) {
                $w[] = 'images: only the first ' . self::MAX_IMAGES . ' images are used';
                $d['images'] = array_slice($d['images'], 0, self::MAX_IMAGES);
            }
        }

        // condition
        $cond = strtolower(str_replace([' ', '-'], '_', $v('condition')));
        if ($cond === '') {
            $d['condition'] = 'new';
        } elseif (isset(self::CONDITIONS[$cond])) {
            $d['condition'] = self::CONDITIONS[$cond];
        } else {
            $e[] = 'condition: "' . $v('condition') . '" must be new, used or refurbished';
            $d['condition'] = 'new';
        }

        // variant attributes
        $d['variant_attributes'] = [];
        $varRaw = $v('variant_attributes');
        if ($varRaw !== '') {
            $parsed = self::parseVariantAttributes($varRaw);
            if ($parsed === null) {
                $e[] = 'variant_attributes: "' . $varRaw . '" must look like "Color:Red|Size:M"';
            } else {
                $d['variant_attributes'] = $parsed;
            }
        }

        // active
        $actRaw = $v('active');
        $act = $actRaw === '' ? true : self::parseBool($actRaw);
        if ($act === null) {
            $e[] = 'active: "' . $actRaw . '" must be 1/0, yes/no or true/false';
            $act = true;
        }
        $d['active'] = $act;

        return new RowResult($line, $d, $raw, $e, $w);
    }

    /** @return array<string,string>|null group => value, null when malformed */
    public static function parseVariantAttributes(string $raw): ?array
    {
        $pairs = str_contains($raw, '|') ? explode('|', $raw) : explode(',', $raw);
        $out = [];
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = preg_split('/\s*[:=]\s*/u', $pair, 2);
            if ($parts === false || count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                return null;
            }
            [$g, $val] = [trim($parts[0]), trim($parts[1])];
            if (mb_strlen($g) > 64 || mb_strlen($val) > 64 || preg_match('/[<>;=#{}]/u', $g . $val)) {
                return null;
            }
            $out[$g] = $val;
        }

        return $out === [] ? null : $out;
    }

    public static function parseBool(string $raw): ?bool
    {
        $s = strtolower(trim($raw));

        return match ($s) {
            '1', 'yes', 'y', 'true', 'on', 'si', 'sì', 'oui', 'ja', 'active', 'enabled' => true,
            '0', 'no', 'n', 'false', 'off', 'non', 'nein', 'inactive', 'disabled' => false,
            default => null,
        };
    }

    public static function isReference(string $s): bool
    {
        return (bool) preg_match('/^[^<>;={}]*$/u', $s);
    }

    public static function isValidEan13(string $ean): bool
    {
        if (!preg_match('/^\d{13}$/', $ean)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $ean[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $ean[12];
    }

    /** Remove active content from vendor HTML (scripts, iframes, event handlers, javascript: URLs). */
    public static function cleanHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|meta|link)\b[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i', '$1="#"', $html) ?? '';

        return trim($html);
    }
}
