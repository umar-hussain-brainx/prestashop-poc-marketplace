<?php
/**
 * bxmpcollections own tables (documented in README):
 *  - bxmp_category_map      vendor category label → PrestaShop category (used by the CSV importer / matching)
 *  - bxmp_collection_state  last materialised schedule status per collection (written by the cron runner, used to
 *                           detect and log activation / deactivation transitions)
 * The collections themselves live in the core tables bxmp_collection / bxmp_collection_product / bxmp_collection_category.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
$p = _DB_PREFIX_ . 'bxmp_';
$engine = 'ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

return [
    "CREATE TABLE IF NOT EXISTS `{$p}category_map` (
        `id_category_map` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_vendor` INT UNSIGNED NOT NULL DEFAULT 0,
        `label` VARCHAR(255) NOT NULL,
        `label_key` VARCHAR(255) NOT NULL,
        `id_category` INT UNSIGNED NOT NULL,
        `date_add` DATETIME NOT NULL,
        `date_upd` DATETIME NOT NULL,
        PRIMARY KEY (`id_category_map`),
        UNIQUE KEY `uniq_vendor_label` (`id_vendor`, `label_key`),
        KEY `idx_category` (`id_category`)
    ) {$engine}",
    "CREATE TABLE IF NOT EXISTS `{$p}collection_state` (
        `id_collection` INT UNSIGNED NOT NULL,
        `status` VARCHAR(16) NOT NULL,
        `date_upd` DATETIME NOT NULL,
        PRIMARY KEY (`id_collection`)
    ) {$engine}",
];
