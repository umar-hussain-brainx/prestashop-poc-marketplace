<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Checkout;

/**
 * Writes the CartPricePlan as cart-scoped native SpecificPrice rows (id_cart set, from_quantity 1, reduction 0,
 * fixed tax-excluded price, exact id_product_attribute so combination impacts are not added on top).
 * Only touches the diff; returns whether something changed.
 */
final class SpecificPriceWriter
{
    /** @return array<string, array{id_specific_price:int, price:float}> */
    public function current(int $idCart): array
    {
        $out = [];
        $rows = \Db::getInstance()->executeS('SELECT id_specific_price, id_product, id_product_attribute, price FROM `' . _DB_PREFIX_
            . 'specific_price` WHERE id_cart = ' . (int) $idCart . ' AND id_specific_price_rule = 0') ?: [];
        foreach ($rows as $r) {
            $k = (int) $r['id_product'] . '-' . (int) $r['id_product_attribute'];
            if (isset($out[$k])) { // duplicate: drop it
                \Db::getInstance()->delete('specific_price', 'id_specific_price = ' . (int) $r['id_specific_price']);
                continue;
            }
            $out[$k] = ['id_specific_price' => (int) $r['id_specific_price'], 'price' => (float) $r['price']];
        }

        return $out;
    }

    public function apply(\Cart $cart, CartPricePlan $plan): bool
    {
        $idCart = (int) $cart->id;
        $existing = $this->current($idCart);
        $changed = false;
        foreach ($existing as $k => $row) {
            if (!isset($plan->unitPrices[$k]) || abs($plan->unitPrices[$k] - $row['price']) > 0.0000005) {
                \Db::getInstance()->delete('specific_price', 'id_specific_price = ' . $row['id_specific_price']);
                unset($existing[$k]);
                $changed = true;
            }
        }
        foreach ($plan->unitPrices as $k => $price) {
            if (isset($existing[$k])) {
                continue;
            }
            [$idProduct, $idAttr] = CartPricePlan::splitKey($k);
            $sp = new \SpecificPrice();
            $sp->id_specific_price_rule = 0;
            $sp->id_cart = $idCart;
            $sp->id_product = $idProduct;
            $sp->id_product_attribute = $idAttr;
            $sp->id_shop = 0;
            $sp->id_shop_group = 0;
            $sp->id_currency = 0;
            $sp->id_country = 0;
            $sp->id_group = 0;
            $sp->id_customer = 0;
            $sp->price = $price;
            $sp->from_quantity = 1;
            $sp->reduction = 0;
            $sp->reduction_tax = 0;
            $sp->reduction_type = 'amount';
            $sp->from = '0000-00-00 00:00:00';
            $sp->to = '0000-00-00 00:00:00';
            $sp->add();
            $changed = true;
        }
        if ($changed) {
            \SpecificPrice::flushCache();
        }

        return $changed;
    }

    public function clear(int $idCart): bool
    {
        $had = (bool) \Db::getInstance()->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'specific_price` WHERE id_cart = ' . (int) $idCart . ' AND id_specific_price_rule = 0');
        if ($had) {
            \Db::getInstance()->delete('specific_price', 'id_cart = ' . (int) $idCart . ' AND id_specific_price_rule = 0');
            \SpecificPrice::flushCache();
        }

        return $had;
    }
}
