<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Repository;

use BrainX\Marketplace\Domain\Optimizer\AllocationResult;

/** Persistence of the optimizer result for a PS cart (bxmp_cart_allocation + bxmp_cart_shipping). */
final class CartAllocationRepository
{
    /** Replaces the stored allocation of a cart in one go. */
    public function save(int $idCart, AllocationResult $result): void
    {
        $db = \Db::getInstance();
        $this->clear($idCart);
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($result->lines() as $l) {
            $rows[] = [
                'id_cart' => $idCart,
                'id_product' => $l->idProduct,
                'id_product_attribute' => $l->idProductAttribute,
                'id_offer' => $l->idOffer,
                'id_vendor' => $l->idVendor,
                'quantity' => $l->quantity,
                'unit_price' => round($l->unitPrice, 6),
                'date_add' => $now,
            ];
        }
        if ($rows !== []) {
            $db->insert('bxmp_cart_allocation', $rows);
        }
        foreach ($result->vendors as $v) {
            $db->insert('bxmp_cart_shipping', ['id_cart' => $idCart, 'id_vendor' => $v->idVendor, 'shipping_cost' => round($v->shippingCost, 6)], false, true, \Db::REPLACE);
        }
    }

    public function clear(int $idCart): void
    {
        $db = \Db::getInstance();
        $db->delete('bxmp_cart_allocation', 'id_cart = ' . (int) $idCart);
        $db->delete('bxmp_cart_shipping', 'id_cart = ' . (int) $idCart);
    }

    public function allocation(int $idCart): array
    {
        return \Db::getInstance()->executeS('SELECT ca.*, v.name AS vendor_name FROM `' . _DB_PREFIX_ . 'bxmp_cart_allocation` ca
            LEFT JOIN `' . _DB_PREFIX_ . 'bxmp_vendor` v ON v.id_vendor = ca.id_vendor
            WHERE ca.id_cart = ' . (int) $idCart . ' ORDER BY ca.id_vendor, ca.id_product') ?: [];
    }

    /** @return array<int, float> idVendor => shipping cost */
    public function shipping(int $idCart): array
    {
        $out = [];
        foreach (\Db::getInstance()->executeS('SELECT id_vendor, shipping_cost FROM `' . _DB_PREFIX_ . 'bxmp_cart_shipping` WHERE id_cart = ' . (int) $idCart) ?: [] as $r) {
            $out[(int) $r['id_vendor']] = (float) $r['shipping_cost'];
        }

        return $out;
    }

    public function totalShipping(int $idCart): float
    {
        return (float) \Db::getInstance()->getValue('SELECT COALESCE(SUM(shipping_cost),0) FROM `' . _DB_PREFIX_ . 'bxmp_cart_shipping` WHERE id_cart = ' . (int) $idCart);
    }
}
