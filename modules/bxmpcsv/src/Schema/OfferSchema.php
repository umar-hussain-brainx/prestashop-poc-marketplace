<?php

declare(strict_types=1);

namespace BrainX\Csv\Schema;

/**
 * Unified vendor offer / catalog CSV schema (see docs/CSV_SCHEMA.md).
 */
final class OfferSchema extends Schema
{
    public const COLUMNS = [
        'vendor_sku' => 'Vendor SKU (unique per vendor, required)',
        'ean' => 'EAN-13 / UPC-A (12 digits) — primary match key',
        'reference' => 'Manufacturer reference / MPN — second match key (with brand)',
        'brand' => 'Brand / manufacturer name',
        'title' => 'Product title (required to create a new master product)',
        'description' => 'Long description (HTML allowed, scripts stripped)',
        'category' => 'Category path "Home > Clothes > Men" or category id',
        'price' => 'Offer price, tax excluded (required)',
        'discount_price' => 'Promotional price, tax excluded (optional, < price)',
        'discount_from' => 'Promotion start (YYYY-MM-DD[ HH:MM[:SS]] or DD/MM/YYYY)',
        'discount_to' => 'Promotion end (date only = end of day)',
        'quantity' => 'Available stock (integer ≥ 0, required)',
        'images' => 'Image URLs separated by "|" (first = cover)',
        'condition' => 'new | used | refurbished (default new)',
        'variant_attributes' => 'Combination attributes "Color:Red|Size:M"',
        'parent_reference' => 'Variant group key: rows sharing it become combinations of one master product',
        'active' => '1/0, yes/no, true/false (default 1)',
    ];

    public const ALIASES = [
        'sku' => 'vendor_sku', 'vendor_reference' => 'vendor_sku', 'seller_sku' => 'vendor_sku', 'codice' => 'vendor_sku', 'item_sku' => 'vendor_sku',
        'ean13' => 'ean', 'gtin' => 'ean', 'gtin13' => 'ean', 'upc' => 'ean', 'barcode' => 'ean',
        'mpn' => 'reference', 'manufacturer_reference' => 'reference', 'ref' => 'reference', 'part_number' => 'reference',
        'manufacturer' => 'brand', 'marca' => 'brand',
        'name' => 'title', 'product_name' => 'title', 'titolo' => 'title', 'nome' => 'title',
        'descrizione' => 'description', 'long_description' => 'description',
        'category_path' => 'category', 'categoria' => 'category',
        'price_tax_excl' => 'price', 'price_excl_tax' => 'price', 'net_price' => 'price', 'prezzo' => 'price', 'unit_price' => 'price',
        'sale_price' => 'discount_price', 'special_price' => 'discount_price', 'promo_price' => 'discount_price',
        'discount_start' => 'discount_from', 'sale_from' => 'discount_from', 'discount_date_from' => 'discount_from',
        'discount_end' => 'discount_to', 'sale_to' => 'discount_to', 'discount_date_to' => 'discount_to',
        'qty' => 'quantity', 'stock' => 'quantity', 'inventory' => 'quantity', 'quantita' => 'quantity', 'availability' => 'quantity',
        'image' => 'images', 'image_urls' => 'images', 'immagini' => 'images',
        'variant' => 'variant_attributes', 'variants' => 'variant_attributes', 'attributes' => 'variant_attributes', 'combination' => 'variant_attributes',
        'parent_sku' => 'parent_reference', 'group_reference' => 'parent_reference', 'variant_group' => 'parent_reference',
        'enabled' => 'active', 'status' => 'active',
    ];

    public function columns(): array
    {
        return self::COLUMNS;
    }

    public function aliases(): array
    {
        return self::ALIASES;
    }

    public function requiredColumns(): array
    {
        return ['vendor_sku', 'price', 'quantity'];
    }

    /** Header of the downloadable template. */
    public static function templateHeader(): array
    {
        return array_keys(self::COLUMNS);
    }
}
