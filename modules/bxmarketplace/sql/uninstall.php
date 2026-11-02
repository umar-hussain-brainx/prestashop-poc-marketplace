<?php
/**
 * BrainX Marketplace — schema uninstall (idempotent: DROP TABLE IF EXISTS).
 *
 * @return string[] SQL statements
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

$p = _DB_PREFIX_ . 'bxmp_';
$tables = [
    'vendor', 'vendor_user', 'offer', 'shipping_rule', 'match_exception', 'import_job', 'feed',
    'cart_allocation', 'cart_shipping', 'vendor_order', 'vendor_order_line', 'commission_adjustment',
    'settlement', 'collection', 'collection_product', 'collection_category', 'log', 'api_token',
];

$sql = [];
foreach ($tables as $t) {
    $sql[] = 'DROP TABLE IF EXISTS `' . $p . $t . '`';
}

return $sql;
