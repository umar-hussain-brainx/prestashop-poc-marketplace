<?php

declare(strict_types=1);

namespace BrainX\Csv\Schema;

/** Vendor order status update CSV (vendor_order_reference, status, tracking_number, carrier_name). */
final class OrderStatusSchema extends Schema
{
    public const COLUMNS = [
        'vendor_order_reference' => 'bxmp_vendor_order.reference (or the numeric id_vendor_order)',
        'status' => 'pending | accepted | shipped | delivered | cancelled (empty = keep)',
        'tracking_number' => 'Carrier tracking number (optional)',
        'carrier_name' => 'Carrier name (optional)',
    ];

    public const ALIASES = [
        'reference' => 'vendor_order_reference', 'order_reference' => 'vendor_order_reference', 'vendor_order' => 'vendor_order_reference',
        'id_vendor_order' => 'vendor_order_reference', 'order' => 'vendor_order_reference',
        'state' => 'status', 'order_status' => 'status', 'stato' => 'status',
        'tracking' => 'tracking_number', 'tracking_code' => 'tracking_number',
        'carrier' => 'carrier_name', 'corriere' => 'carrier_name',
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
        return ['vendor_order_reference', 'status'];
    }
}
