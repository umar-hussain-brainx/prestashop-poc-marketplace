<?php
/**
 * BrainX Marketplace — schema install (idempotent: CREATE TABLE IF NOT EXISTS).
 *
 * Table set and columns are the binding contract from docs/ARCHITECTURE.md.
 * All money columns are tax excluded, DECIMAL(20,6).
 *
 * @return string[] SQL statements (prefix already applied)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

$p = _DB_PREFIX_ . 'bxmp_';
$engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$sql = [];

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}vendor` (
    `id_vendor` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `code` VARCHAR(64) NOT NULL,
    `email` VARCHAR(255) NULL,
    `company` VARCHAR(255) NULL,
    `vat_number` VARCHAR(32) NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `commission_fixed` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `payout_account` VARCHAR(255) NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_vendor`),
    UNIQUE KEY `uniq_code` (`code`),
    KEY `idx_active` (`active`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}vendor_user` (
    `id_vendor_user` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `id_customer` INT UNSIGNED NOT NULL,
    `role` ENUM('owner','manager','staff') NOT NULL DEFAULT 'staff',
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_vendor_user`),
    UNIQUE KEY `uniq_vendor_customer` (`id_vendor`, `id_customer`),
    KEY `idx_customer` (`id_customer`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}offer` (
    `id_offer` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `id_product` INT UNSIGNED NOT NULL,
    `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
    `vendor_sku` VARCHAR(64) NULL,
    `ean13` VARCHAR(13) NULL,
    `price` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `discount_price` DECIMAL(20,6) NULL,
    `discount_from` DATETIME NULL,
    `discount_to` DATETIME NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `condition` VARCHAR(16) NOT NULL DEFAULT 'new',
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_offer`),
    UNIQUE KEY `uniq_vendor_product` (`id_vendor`, `id_product`, `id_product_attribute`),
    KEY `idx_product` (`id_product`, `id_product_attribute`, `active`),
    KEY `idx_ean13` (`ean13`),
    KEY `idx_vendor_sku` (`id_vendor`, `vendor_sku`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}shipping_rule` (
    `id_shipping_rule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `id_country` INT UNSIGNED NOT NULL DEFAULT 0,
    `zipcode_pattern` VARCHAR(255) NULL,
    `excluded` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `base_cost` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `free_threshold` DECIMAL(20,6) NULL,
    `reduced_threshold` DECIMAL(20,6) NULL,
    `reduced_cost` DECIMAL(20,6) NULL,
    `priority` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_shipping_rule`),
    KEY `idx_vendor_country` (`id_vendor`, `id_country`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}match_exception` (
    `id_match_exception` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `id_import_job` INT UNSIGNED NULL,
    `vendor_sku` VARCHAR(64) NULL,
    `ean13` VARCHAR(32) NULL,
    `brand` VARCHAR(128) NULL,
    `title` VARCHAR(255) NULL,
    `payload` MEDIUMTEXT NULL,
    `reason` VARCHAR(64) NULL,
    `candidates` TEXT NULL,
    `status` ENUM('pending','resolved','rejected') NOT NULL DEFAULT 'pending',
    `id_product_resolved` INT UNSIGNED NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_match_exception`),
    KEY `idx_vendor_status` (`id_vendor`, `status`),
    KEY `idx_job` (`id_import_job`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}import_job` (
    `id_import_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `type` ENUM('offers','order_status') NOT NULL DEFAULT 'offers',
    `source` ENUM('upload','url','path') NOT NULL DEFAULT 'upload',
    `file_path` VARCHAR(512) NULL,
    `status` ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
    `rows_total` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_ok` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_error` INT UNSIGNED NOT NULL DEFAULT 0,
    `log` MEDIUMTEXT NULL,
    `date_add` DATETIME NOT NULL,
    `date_start` DATETIME NULL,
    `date_end` DATETIME NULL,
    PRIMARY KEY (`id_import_job`),
    KEY `idx_status` (`status`),
    KEY `idx_vendor` (`id_vendor`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}feed` (
    `id_feed` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `url_or_path` VARCHAR(512) NOT NULL,
    `schedule_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
    `last_run` DATETIME NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id_feed`),
    KEY `idx_vendor` (`id_vendor`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}cart_allocation` (
    `id_cart_allocation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_cart` INT UNSIGNED NOT NULL,
    `id_product` INT UNSIGNED NOT NULL,
    `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
    `id_offer` INT UNSIGNED NOT NULL,
    `id_vendor` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `unit_price` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_cart_allocation`),
    KEY `idx_cart` (`id_cart`),
    KEY `idx_cart_product` (`id_cart`, `id_product`, `id_product_attribute`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}cart_shipping` (
    `id_cart` INT UNSIGNED NOT NULL,
    `id_vendor` INT UNSIGNED NOT NULL,
    `shipping_cost` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    PRIMARY KEY (`id_cart`, `id_vendor`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}vendor_order` (
    `id_vendor_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order` INT UNSIGNED NOT NULL,
    `id_vendor` INT UNSIGNED NOT NULL,
    `reference` VARCHAR(64) NULL,
    `total_products` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `total_shipping` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `total_paid` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `commission_amount` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `vendor_payout` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `status` ENUM('pending','accepted','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    `tracking_number` VARCHAR(128) NULL,
    `carrier_name` VARCHAR(128) NULL,
    `payment_transfer_ref` VARCHAR(128) NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_vendor_order`),
    UNIQUE KEY `uniq_order_vendor` (`id_order`, `id_vendor`),
    KEY `idx_vendor_status` (`id_vendor`, `status`),
    KEY `idx_date` (`date_add`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}vendor_order_line` (
    `id_vendor_order_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor_order` INT UNSIGNED NOT NULL,
    `id_offer` INT UNSIGNED NOT NULL DEFAULT 0,
    `id_product` INT UNSIGNED NOT NULL,
    `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
    `product_name` VARCHAR(255) NULL,
    `vendor_sku` VARCHAR(64) NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
    `unit_price` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `commission_amount` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    PRIMARY KEY (`id_vendor_order_line`),
    KEY `idx_vendor_order` (`id_vendor_order`),
    KEY `idx_product` (`id_product`, `id_product_attribute`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}commission_adjustment` (
    `id_adjustment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `id_vendor_order` INT UNSIGNED NULL,
    `amount` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `reason` VARCHAR(255) NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_adjustment`),
    KEY `idx_vendor` (`id_vendor`, `date_add`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}settlement` (
    `id_settlement` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_vendor` INT UNSIGNED NOT NULL,
    `period_from` DATE NOT NULL,
    `period_to` DATE NOT NULL,
    `gross` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `commission` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `adjustments` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `net_payout` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `status` ENUM('draft','approved','paid') NOT NULL DEFAULT 'draft',
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_settlement`),
    KEY `idx_vendor_period` (`id_vendor`, `period_from`, `period_to`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}collection` (
    `id_collection` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `slug` VARCHAR(128) NOT NULL,
    `description` TEXT NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_start` DATETIME NULL,
    `date_end` DATETIME NULL,
    `position` INT UNSIGNED NOT NULL DEFAULT 0,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_collection`),
    UNIQUE KEY `uniq_slug` (`slug`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}collection_product` (
    `id_collection` INT UNSIGNED NOT NULL,
    `id_product` INT UNSIGNED NOT NULL,
    `position` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_collection`, `id_product`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}collection_category` (
    `id_collection` INT UNSIGNED NOT NULL,
    `id_category` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id_collection`, `id_category`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}log` (
    `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel` VARCHAR(32) NOT NULL,
    `level` VARCHAR(16) NOT NULL,
    `message` TEXT NOT NULL,
    `context` TEXT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_log`),
    KEY `idx_channel_date` (`channel`, `date_add`)
) {$engine}";

$sql[] = "CREATE TABLE IF NOT EXISTS `{$p}api_token` (
    `id_api_token` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token` CHAR(64) NOT NULL,
    `label` VARCHAR(128) NULL,
    `id_vendor` INT UNSIGNED NULL,
    `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_api_token`),
    UNIQUE KEY `uniq_token` (`token`)
) {$engine}";

return $sql;
