<?php
/**
 * Demo checkout setup (idempotent). Run inside the container as www-data:
 *   php modules/bxmpcheckout/tools/setup_demo.php
 *
 *  - (re)creates the marketplace carrier and makes it the default carrier;
 *  - enables + configures the native offline payment module ps_wirepayment (bank wire) for the demo;
 *  - prints the carrier id and the expected client scenario totals.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', dirname(__DIR__, 3) . '/admin-dev');
}
require_once dirname(__DIR__, 3) . '/config/config.inc.php';

$module = Module::getInstanceByName('bxmpcheckout');
if (!$module || !Module::isInstalled('bxmpcheckout')) {
    fwrite(STDERR, "bxmpcheckout is not installed\n");
    exit(1);
}
$idCarrier = (new BrainX\MarketplaceCheckout\Carrier\CarrierInstaller('bxmpcheckout'))->install();
Configuration::updateValue('PS_CARRIER_DEFAULT', $idCarrier);
Configuration::updateValue('BXMPCO_EXCLUSIVE_CARRIER', 1);
echo "Marketplace carrier #$idCarrier is the default carrier (exclusive on marketplace carts)\n";

$wire = Module::getInstanceByName('ps_wirepayment');
if ($wire) {
    if (!Module::isInstalled('ps_wirepayment')) {
        $wire->install();
    }
    if (!Module::isEnabled('ps_wirepayment')) {
        $wire->enable();
    }
    if (!Configuration::get('BANK_WIRE_OWNER')) {
        Configuration::updateValue('BANK_WIRE_OWNER', 'BrainX Marketplace S.r.l. (escrow)');
    }
    if (!Configuration::get('BANK_WIRE_DETAILS')) {
        Configuration::updateValue('BANK_WIRE_DETAILS', 'IBAN IT60 X054 2811 1010 0000 0123 456 - BIC BPMOIT22 (demo)');
    }
    if (!Configuration::get('BANK_WIRE_ADDRESS')) {
        Configuration::updateValue('BANK_WIRE_ADDRESS', 'Via Dante 1, 20121 Milano');
    }
    echo "ps_wirepayment enabled and configured\n";
}

$split = (string) Configuration::get('BXMPCO_PAYMENT_SPLITTER');
echo "Payment splitter: $split (" . Configuration::get('BXMPCO_MANGOPAY_MODE') . ")\n";
exit(0);
