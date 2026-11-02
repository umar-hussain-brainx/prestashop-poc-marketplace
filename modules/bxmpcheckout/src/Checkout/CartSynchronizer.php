<?php

declare(strict_types=1);

namespace BrainX\MarketplaceCheckout\Checkout;

use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\UnavailableProductException;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\CartAllocationRepository;
use BrainX\Marketplace\Infrastructure\Service\CartOptimizationService;

/**
 * Keeps a PrestaShop cart in sync with the CartOptimizer:
 *   cart lines + delivery destination → CartOptimizationService → bxmp_cart_allocation / bxmp_cart_shipping
 *   → cart-scoped SpecificPrice per line (blended allocated unit price) → PS-native totals = optimized item totals.
 *
 * Idempotent and cheap to call repeatedly: a per-request memo keyed by a signature of (lines, quantities, destination)
 * avoids re-running the optimizer, a re-entrancy guard prevents recursion (actionCartSave → ... → actionCartSave), and
 * the SpecificPrice writer only writes diffs. Carts that already became an order are never touched.
 */
final class CartSynchronizer
{
    /** @var array<int, SyncState> idCart => last state (this request) */
    private static array $memo = [];
    private static bool $running = false;

    private CartOptimizationService $service;
    private CartAllocationRepository $allocations;
    private SpecificPriceWriter $prices;

    public function __construct(?CartOptimizationService $service = null, ?CartAllocationRepository $allocations = null, ?SpecificPriceWriter $prices = null)
    {
        $this->service = $service ?? new CartOptimizationService();
        $this->allocations = $allocations ?? new CartAllocationRepository();
        $this->prices = $prices ?? new SpecificPriceWriter();
    }

    public static function lastState(int $idCart): ?SyncState
    {
        return self::$memo[$idCart] ?? null;
    }

    public static function resetMemo(): void
    {
        self::$memo = [];
    }

    public function sync(\Cart $cart, bool $force = false): SyncState
    {
        $idCart = (int) $cart->id;
        if ($idCart <= 0) {
            return new SyncState(SyncState::EMPTY);
        }
        if (self::$running) {
            return self::$memo[$idCart] ?? new SyncState(SyncState::EMPTY);
        }
        if ($cart->orderExists()) {
            return self::$memo[$idCart] = new SyncState(SyncState::ORDERED, null, null, [], 'ordered');
        }

        self::$running = true;
        try {
            $lines = $this->basketLines($cart);
            $destination = self::destinationFor($cart);
            $signature = md5(json_encode([$lines, $destination->toArray()]));
            if (!$force && isset(self::$memo[$idCart]) && self::$memo[$idCart]->signature === $signature) {
                return self::$memo[$idCart];
            }

            if ($lines === []) {
                $this->allocations->clear($idCart);
                $changed = $this->prices->clear($idCart);
                $state = new SyncState(SyncState::EMPTY, null, $destination, [], $signature, $changed);
            } else {
                $basket = array_map(static fn (array $l) => new BasketLine($l[0], $l[1], $l[2]), $lines);
                try {
                    $result = $this->service->optimize($basket, $destination);
                    $this->allocations->save($idCart, $result);
                    $changed = $this->prices->apply($cart, CartPricePlan::fromResult($result));
                    $state = new SyncState(SyncState::OK, $result, $destination, [], $signature, $changed);
                } catch (UnavailableProductException $e) {
                    // Never substitute: keep PS catalog prices, drop our allocation and block checkout.
                    $this->allocations->clear($idCart);
                    $changed = $this->prices->clear($idCart);
                    $unavailable = array_map(static function (array $u) use ($cart): array {
                        $u['name'] = self::productName((int) $u['id_product'], (int) $u['id_product_attribute'], (int) $cart->id_lang);

                        return $u;
                    }, $e->lines);
                    $state = new SyncState(SyncState::UNAVAILABLE, null, $destination, $unavailable, $signature, $changed);
                    Logger::channel('checkout')->info('Cart blocked: products unavailable', ['id_cart' => $idCart, 'lines' => $e->lines]);
                }
            }
            self::$memo[$idCart] = $state;
            self::flushCartCaches($cart);

            return $state;
        } catch (\Throwable $e) {
            Logger::channel('checkout')->error('Cart synchronisation failed: ' . $e->getMessage(), ['id_cart' => $idCart, 'file' => $e->getFile() . ':' . $e->getLine()]);

            return new SyncState(SyncState::UNAVAILABLE, null, null, [], '', false);
        } finally {
            self::$running = false;
        }
    }

    /** @return array<int, array{0:int,1:int,2:int}> aggregated [idProduct, idAttr, qty] (customizations merged) */
    private function basketLines(\Cart $cart): array
    {
        $cart->resetProductRelatedStaticCache();
        $agg = [];
        foreach ($cart->getProducts(true) as $p) {
            $k = (int) $p['id_product'] . '-' . (int) $p['id_product_attribute'];
            $agg[$k] ??= [(int) $p['id_product'], (int) $p['id_product_attribute'], 0];
            $agg[$k][2] += (int) $p['cart_quantity'];
        }
        ksort($agg);

        return array_values($agg);
    }

    /** Delivery destination of the cart (address → customer's first address → shop default country). */
    public static function destinationFor(\Cart $cart): Destination
    {
        $idAddress = (int) $cart->id_address_delivery;
        if ($idAddress <= 0 && (int) $cart->id_customer > 0) {
            $idAddress = (int) \Address::getFirstCustomerAddressId((int) $cart->id_customer);
        }

        return CartOptimizationService::destinationFromAddress($idAddress);
    }

    public static function productName(int $idProduct, int $idAttr, int $idLang): string
    {
        $idLang = $idLang ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $name = \Product::getProductName($idProduct, $idAttr ?: null, $idLang);

        return $name ? (string) $name : 'product #' . $idProduct;
    }

    /** Drops every PS in-request cache that may hold prices / shipping computed before our SpecificPrices changed. */
    public static function flushCartCaches(\Cart $cart): void
    {
        \SpecificPrice::flushCache();
        \Product::flushPriceCache();
        \Cart::resetStaticCache();
        $cart->resetProductRelatedStaticCache();
        $ctxCart = \Context::getContext()->cart ?? null;
        if ($ctxCart instanceof \Cart && $ctxCart !== $cart) {
            $ctxCart->resetProductRelatedStaticCache();
        }
        \Cache::clean('getPackageShippingCost_' . (int) $cart->id . '_*');
        \Cache::clean('presentedCart_*');
    }
}
