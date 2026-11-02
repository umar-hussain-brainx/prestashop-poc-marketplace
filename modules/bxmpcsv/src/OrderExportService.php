<?php

declare(strict_types=1);

namespace BrainX\Csv;

use BrainX\Csv\Parser\CsvWriter;

/**
 * Vendor order export (one CSV line per vendor order line) with the delivery data a vendor needs to fulfil:
 *
 *   $csv = (new OrderExportService())->exportCsv($idVendor, ['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'status' => ['pending', 'accepted']]);
 *
 * $idVendor = 0 exports all vendors (admin). Filters: date_from / date_to (Y-m-d, inclusive, on date_add),
 * status (string or list), id_order. Delimiter ";" + UTF-8 BOM by default (opens directly in Excel).
 * The resulting file can be edited and re-imported as an order status CSV (vendor_order_reference, status,
 * tracking_number, carrier_name columns are present).
 */
final class OrderExportService
{
    public const COLUMNS = [
        'vendor_order_reference', 'id_vendor_order', 'vendor', 'order_reference', 'id_order', 'order_date', 'status',
        'line', 'vendor_sku', 'ean13', 'product_name', 'id_product', 'id_product_attribute', 'quantity', 'unit_price_tax_excl', 'line_total_tax_excl',
        'total_products', 'total_shipping', 'total_paid', 'commission_amount', 'vendor_payout',
        'customer_name', 'customer_email', 'delivery_company', 'delivery_address1', 'delivery_address2', 'delivery_postcode',
        'delivery_city', 'delivery_state', 'delivery_country', 'delivery_phone',
        'tracking_number', 'carrier_name',
    ];

    /** @return array<int, array<string,mixed>> */
    public function rows(int $idVendor, array $filters = []): array
    {
        $p = _DB_PREFIX_;
        $where = ['1'];
        if ($idVendor > 0) {
            $where[] = 'vo.id_vendor = ' . $idVendor;
        }
        if (!empty($filters['date_from']) && ($d = self::date((string) $filters['date_from']))) {
            $where[] = "vo.date_add >= '" . pSQL($d) . " 00:00:00'";
        }
        if (!empty($filters['date_to']) && ($d = self::date((string) $filters['date_to']))) {
            $where[] = "vo.date_add <= '" . pSQL($d) . " 23:59:59'";
        }
        $statuses = array_values(array_intersect((array) ($filters['status'] ?? []), \BxmpVendorOrder::STATUSES));
        if ($statuses !== []) {
            $where[] = 'vo.status IN (' . implode(',', array_map(static fn ($s) => "'" . pSQL($s) . "'", $statuses)) . ')';
        }
        if (!empty($filters['id_order'])) {
            $where[] = 'vo.id_order = ' . (int) $filters['id_order'];
        }
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $sql = "SELECT vo.*, v.name AS vendor_name, o.reference AS order_reference,
                l.id_vendor_order_line, l.vendor_sku, l.product_name, l.id_product, l.id_product_attribute, l.quantity, l.unit_price,
                COALESCE(NULLIF(pa.ean13, ''), p.ean13, '') AS ean13,
                c.firstname, c.lastname, c.email,
                a.company, a.address1, a.address2, a.postcode, a.city, a.phone, a.phone_mobile, a.firstname AS a_firstname, a.lastname AS a_lastname,
                st.name AS state_name, cl.name AS country_name
            FROM `{$p}bxmp_vendor_order` vo
            INNER JOIN `{$p}bxmp_vendor` v ON v.id_vendor = vo.id_vendor
            LEFT JOIN `{$p}bxmp_vendor_order_line` l ON l.id_vendor_order = vo.id_vendor_order
            LEFT JOIN `{$p}product` p ON p.id_product = l.id_product
            LEFT JOIN `{$p}product_attribute` pa ON pa.id_product_attribute = l.id_product_attribute AND l.id_product_attribute > 0
            LEFT JOIN `{$p}orders` o ON o.id_order = vo.id_order
            LEFT JOIN `{$p}customer` c ON c.id_customer = o.id_customer
            LEFT JOIN `{$p}address` a ON a.id_address = o.id_address_delivery
            LEFT JOIN `{$p}state` st ON st.id_state = a.id_state
            LEFT JOIN `{$p}country_lang` cl ON cl.id_country = a.id_country AND cl.id_lang = {$idLang}
            WHERE " . implode(' AND ', $where) . '
            ORDER BY vo.date_add, vo.id_vendor_order, l.id_vendor_order_line';
        $out = [];
        $lineNo = [];
        foreach (\Db::getInstance()->executeS($sql) ?: [] as $r) {
            $idVo = (int) $r['id_vendor_order'];
            $lineNo[$idVo] = ($lineNo[$idVo] ?? 0) + 1;
            $qty = (int) $r['quantity'];
            $unit = (float) $r['unit_price'];
            $out[] = [
                'vendor_order_reference' => $r['reference'] ?: (string) $idVo,
                'id_vendor_order' => $idVo,
                'vendor' => $r['vendor_name'],
                'order_reference' => $r['order_reference'],
                'id_order' => (int) $r['id_order'],
                'order_date' => $r['date_add'],
                'status' => $r['status'],
                'line' => $r['id_vendor_order_line'] ? $lineNo[$idVo] : '',
                'vendor_sku' => $r['vendor_sku'],
                'ean13' => $r['ean13'],
                'product_name' => $r['product_name'],
                'id_product' => $r['id_product'],
                'id_product_attribute' => $r['id_product_attribute'],
                'quantity' => $r['id_vendor_order_line'] ? $qty : '',
                'unit_price_tax_excl' => $r['id_vendor_order_line'] ? self::money($unit) : '',
                'line_total_tax_excl' => $r['id_vendor_order_line'] ? self::money($unit * $qty) : '',
                'total_products' => self::money((float) $r['total_products']),
                'total_shipping' => self::money((float) $r['total_shipping']),
                'total_paid' => self::money((float) $r['total_paid']),
                'commission_amount' => self::money((float) $r['commission_amount']),
                'vendor_payout' => self::money((float) $r['vendor_payout']),
                'customer_name' => trim(($r['a_firstname'] ?: $r['firstname']) . ' ' . ($r['a_lastname'] ?: $r['lastname'])),
                'customer_email' => $r['email'],
                'delivery_company' => $r['company'],
                'delivery_address1' => $r['address1'],
                'delivery_address2' => $r['address2'],
                'delivery_postcode' => $r['postcode'],
                'delivery_city' => $r['city'],
                'delivery_state' => $r['state_name'],
                'delivery_country' => $r['country_name'],
                'delivery_phone' => $r['phone_mobile'] ?: $r['phone'],
                'tracking_number' => $r['tracking_number'],
                'carrier_name' => $r['carrier_name'],
            ];
        }

        return $out;
    }

    /** CSV content (string). */
    public function exportCsv(int $idVendor, array $filters = [], string $delimiter = ';'): string
    {
        return CsvWriter::toString(self::COLUMNS, $this->rows($idVendor, $filters), $delimiter, true);
    }

    /** Write the CSV to a file, returns the number of lines exported. */
    public function exportToFile(int $idVendor, string $path, array $filters = [], string $delimiter = ';'): int
    {
        $rows = $this->rows($idVendor, $filters);
        file_put_contents($path, CsvWriter::toString(self::COLUMNS, $rows, $delimiter, true));

        return count($rows);
    }

    /** Send the CSV as a download and stop (BO / front controllers). */
    public function download(int $idVendor, array $filters = [], ?string $filename = null): never
    {
        $csv = $this->exportCsv($idVendor, $filters);
        $filename ??= 'vendor-orders-' . ($idVendor ?: 'all') . '-' . date('Ymd-His') . '.csv';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private static function date(string $d): ?string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($d));

        return $dt ? $dt->format('Y-m-d') : null;
    }
}
