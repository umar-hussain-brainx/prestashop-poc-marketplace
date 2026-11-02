<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
$p = _DB_PREFIX_ . 'bxmp_';

return [
    "DROP TABLE IF EXISTS `{$p}category_map`",
    "DROP TABLE IF EXISTS `{$p}collection_state`",
];
