<?php
/**
 * Single include for other modules / scripts: core (BrainX\Marketplace\, Bxmp* ObjectModels) + this module (BrainX\Csv\).
 *
 *   require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';
 */
require_once dirname(__DIR__) . '/bxmarketplace/vendor/autoload.php';
require_once __DIR__ . '/vendor/autoload.php';
