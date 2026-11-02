<?php

declare(strict_types=1);

namespace BrainX\Csv\Validation;

use BrainX\Marketplace\Domain\Matching\VendorRow;

/**
 * Maps a validated offer row (OfferRowValidator data) to the structures used downstream:
 * the core MatchingEngine VendorRow, the OfferRepository::upsert() payload and a master product draft.
 */
final class OfferRowMapper
{
    /** @param array<string,mixed> $d validated data */
    public static function toVendorRow(array $d): VendorRow
    {
        return new VendorRow(
            $d['ean'] ?? null,
            $d['reference'] ?? null,
            $d['brand'] ?? null,
            (string) ($d['title'] ?? ''),
            $d['vendor_sku'] ?? null,
        );
    }

    /**
     * Payload for BrainX\Marketplace\Infrastructure\Repository\OfferRepository::upsert().
     *
     * @param array<string,mixed> $d
     */
    public static function toOffer(array $d, int $idVendor, int $idProduct, int $idProductAttribute = 0): array
    {
        $hasDiscount = ($d['discount_price'] ?? null) !== null;

        return [
            'id_vendor' => $idVendor,
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'vendor_sku' => (string) $d['vendor_sku'],
            'ean13' => $d['ean'] ?? null,
            'price' => (float) $d['price'],
            'discount_price' => $hasDiscount ? (float) $d['discount_price'] : null,
            'discount_from' => $hasDiscount ? ($d['discount_from'] ?? null) : null,
            'discount_to' => $hasDiscount ? ($d['discount_to'] ?? null) : null,
            'quantity' => max(0, (int) $d['quantity']),
            'condition' => (string) ($d['condition'] ?? 'new'),
            'active' => (bool) ($d['active'] ?? true),
        ];
    }

    /**
     * Master product draft (PS product + optional combination) built from a vendor row.
     *
     * @param array<string,mixed> $d
     */
    public static function toProductDraft(array $d): array
    {
        $title = trim((string) ($d['title'] ?? ''));
        $description = (string) ($d['description'] ?? '');
        $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        $short = mb_strlen($plain) > 400 ? rtrim(mb_substr($plain, 0, 397)) . '…' : $plain;
        $variants = (array) ($d['variant_attributes'] ?? []);
        $reference = $d['reference'] ?? null;

        return [
            'name' => $title,
            'description' => $description,
            'description_short' => $short === '' ? '' : '<p>' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</p>',
            'ean13' => $d['ean'] ?? null,
            // the product reference is the variant group key when present, else the MPN / reference
            'reference' => $d['parent_reference'] ?? $reference,
            'mpn' => $reference !== null && mb_strlen((string) $reference) <= 40 ? $reference : null,
            'brand' => $d['brand'] ?? null,
            'category' => $d['category'] ?? null,
            'price' => (float) ($d['price'] ?? 0),
            'condition' => (string) ($d['condition'] ?? 'new'),
            'images' => array_values((array) ($d['images'] ?? [])),
            'variant_attributes' => $variants,
            'parent_reference' => $d['parent_reference'] ?? null,
            'combination_reference' => $reference,
        ];
    }

    /** Category path "Home > Clothes > Men" / "Clothes/Men" → ["Clothes", "Men"] (a leading Home/root is dropped). */
    public static function categoryPath(string $category): array
    {
        $parts = preg_split('#\s*(?:>|/|\\\\)\s*#u', trim($category)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));
        if ($parts !== [] && in_array(mb_strtolower($parts[0]), ['home', 'root', 'accueil', 'inicio'], true)) {
            array_shift($parts);
        }

        return $parts;
    }
}
