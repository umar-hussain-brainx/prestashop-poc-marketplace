<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure\Api;

use BrainX\Marketplace\Domain\Optimizer\BasketLine;
use BrainX\Marketplace\Domain\Optimizer\CartOptimizer;
use BrainX\Marketplace\Domain\Optimizer\Destination;
use BrainX\Marketplace\Domain\Optimizer\OfferCandidate;
use BrainX\Marketplace\Domain\Optimizer\ShippingPolicy;
use BrainX\Marketplace\Domain\Optimizer\UnavailableProductException;
use BrainX\Marketplace\Domain\Pricing\ComparisonRow;
use BrainX\Marketplace\Domain\Shipping\ShippingRule;
use BrainX\Marketplace\Infrastructure\Logger;
use BrainX\Marketplace\Infrastructure\Repository\OfferRepository;
use BrainX\Marketplace\Infrastructure\Repository\ShippingRuleRepository;
use BrainX\Marketplace\Infrastructure\Repository\VendorOrderRepository;
use BrainX\Marketplace\Infrastructure\Repository\VendorRepository;
use BrainX\Marketplace\Infrastructure\Service\CartOptimizationService;
use BrainX\Marketplace\Infrastructure\Service\PriceComparisonService;

/**
 * Internal JSON API router (see docs/API.md). Pure dispatch: (method, resource path, query, body) → [status, payload].
 * Authentication happens in the front controller; this class enforces token scope (admin vs vendor).
 */
final class Router
{
    private const MAX_LINES = 500;

    public function __construct(
        private readonly ApiContext $ctx,
        private readonly VendorRepository $vendors = new VendorRepository(),
        private readonly OfferRepository $offers = new OfferRepository(),
        private readonly ShippingRuleRepository $shipping = new ShippingRuleRepository(),
        private readonly VendorOrderRepository $vendorOrders = new VendorOrderRepository(),
    ) {
    }

    /** @return array{0:int,1:array} */
    public function dispatch(string $method, string $resource, array $query, ?array $body): array
    {
        $method = strtoupper($method);
        $path = array_values(array_filter(explode('/', trim($resource, '/')), static fn ($s) => $s !== ''));
        $route = $path[0] ?? '';

        return match (true) {
            $route === 'me' && $method === 'GET' => [200, ['data' => ['id_api_token' => $this->ctx->idApiToken, 'scope' => $this->ctx->isAdmin() ? 'admin' : 'vendor', 'id_vendor' => $this->ctx->idVendor, 'label' => $this->ctx->label]]],
            $route === 'vendors' && $method === 'GET' && count($path) === 1 => $this->listVendors(),
            $route === 'vendors' && $method === 'GET' && count($path) === 2 => $this->getVendor((int) $path[1]),
            $route === 'offers' && $method === 'GET' && count($path) === 1 => $this->listOffers($query),
            $route === 'offers' && $method === 'GET' && count($path) === 2 => $this->getOffer((int) $path[1]),
            $route === 'offers' && $method === 'POST' && count($path) === 1 => $this->createOffers($body ?? []),
            $route === 'offers' && in_array($method, ['PUT', 'PATCH'], true) && count($path) === 2 => $this->updateOffer((int) $path[1], $body ?? []),
            $route === 'products' && $method === 'GET' && count($path) === 3 && $path[2] === 'offers' => $this->productOffers((int) $path[1], $query),
            $route === 'cart' && $method === 'POST' && ($path[1] ?? '') === 'optimize' => $this->optimize($body ?? []),
            $route === 'vendor-orders' && $method === 'GET' && count($path) === 1 => $this->listVendorOrders($query),
            $route === 'vendor-orders' && $method === 'GET' && count($path) === 2 => $this->getVendorOrder((int) $path[1]),
            in_array($route, ['me', 'vendors', 'offers', 'products', 'cart', 'vendor-orders'], true) => throw new ApiException(405, sprintf('Method %s not allowed on %s', $method, $resource), 'method_not_allowed'),
            default => throw new ApiException(404, 'Unknown resource: ' . $resource, 'not_found'),
        };
    }

    // ------------------------------------------------------------------ vendors

    private function listVendors(): array
    {
        $rows = $this->ctx->isAdmin() ? $this->vendors->findAll() : array_filter([$this->vendors->find((int) $this->ctx->idVendor)]);

        return [200, ['data' => array_values(array_map([$this, 'vendorOut'], $rows)), 'meta' => ['count' => count($rows)]]];
    }

    private function getVendor(int $id): array
    {
        $this->ctx->assertCanAccessVendor($id);
        $row = $this->vendors->find($id) ?? throw new ApiException(404, 'Vendor not found', 'not_found');
        $out = $this->vendorOut($row);
        $out['shipping_rules'] = array_map(static fn ($r) => ShippingRule::fromArray($r)->toArray(), $this->shipping->findByVendor($id));

        return [200, ['data' => $out]];
    }

    private function vendorOut(array $r): array
    {
        return [
            'id_vendor' => (int) $r['id_vendor'],
            'name' => $r['name'],
            'code' => $r['code'],
            'email' => $r['email'],
            'company' => $r['company'],
            'vat_number' => $r['vat_number'],
            'active' => (bool) $r['active'],
            'commission_rate' => (float) $r['commission_rate'],
            'commission_fixed' => (float) $r['commission_fixed'],
            'date_add' => $r['date_add'],
            'date_upd' => $r['date_upd'],
        ];
    }

    // ------------------------------------------------------------------ offers

    private function listOffers(array $q): array
    {
        $filters = [];
        foreach (['id_vendor', 'id_product', 'id_product_attribute'] as $f) {
            if (isset($q[$f]) && $q[$f] !== '') {
                $filters[$f] = (int) $q[$f];
            }
        }
        if (isset($q['active']) && $q['active'] !== '') {
            $filters['active'] = (bool) (int) $q['active'];
        }
        if (!empty($q['ean13'])) {
            $filters['ean13'] = (string) $q['ean13'];
        }
        if (!$this->ctx->isAdmin()) {
            if (isset($filters['id_vendor']) && $filters['id_vendor'] !== $this->ctx->idVendor) {
                throw new ApiException(403, 'Token is scoped to another vendor', 'forbidden');
            }
            $filters['id_vendor'] = $this->ctx->idVendor;
        }
        $limit = max(1, min(1000, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $rows = $this->offers->search($filters, $limit, $offset);

        return [200, ['data' => array_map([$this, 'offerOut'], $rows), 'meta' => ['count' => count($rows), 'total' => $this->offers->count($filters), 'limit' => $limit, 'offset' => $offset]]];
    }

    private function getOffer(int $id): array
    {
        $row = $this->offers->find($id) ?? throw new ApiException(404, 'Offer not found', 'not_found');
        $this->ctx->assertCanAccessVendor((int) $row['id_vendor']);

        return [200, ['data' => $this->offerOut($row)]];
    }

    /** Body: one offer object, or {"offers": [ ... ]} (upsert on id_vendor+id_product+id_product_attribute). */
    private function createOffers(array $body): array
    {
        $items = isset($body['offers']) && is_array($body['offers']) ? $body['offers'] : [$body];
        if ($items === [] || count($items) > self::MAX_LINES) {
            throw new ApiException(422, 'Provide 1..' . self::MAX_LINES . ' offers', 'validation_error');
        }
        $saved = [];
        $errors = [];
        foreach (array_values($items) as $i => $item) {
            try {
                if (!is_array($item)) {
                    throw new ApiException(422, 'Offer must be an object', 'validation_error');
                }
                $saved[] = $this->offerOut($this->offers->find($this->saveOffer($item, null)) ?? []);
            } catch (ApiException $e) {
                if (count($items) === 1) {
                    throw $e;
                }
                $errors[] = ['index' => $i, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }
        Logger::channel('api')->info('Offers upserted', ['token' => $this->ctx->idApiToken, 'saved' => count($saved), 'errors' => count($errors)]);

        if (!isset($body['offers'])) {
            return [201, ['data' => $saved[0]]];
        }

        return [$errors === [] ? 201 : 207, ['data' => $saved, 'errors' => $errors, 'meta' => ['saved' => count($saved), 'failed' => count($errors)]]];
    }

    private function updateOffer(int $id, array $body): array
    {
        $row = $this->offers->find($id) ?? throw new ApiException(404, 'Offer not found', 'not_found');
        $this->ctx->assertCanAccessVendor((int) $row['id_vendor']);
        foreach (['id_vendor', 'id_product', 'id_product_attribute'] as $immutable) {
            if (isset($body[$immutable]) && (int) $body[$immutable] !== (int) $row[$immutable]) {
                throw new ApiException(422, $immutable . ' cannot be changed', 'validation_error');
            }
        }
        $this->saveOffer($body + ['id_vendor' => $row['id_vendor'], 'id_product' => $row['id_product'], 'id_product_attribute' => $row['id_product_attribute']], $row);

        return [200, ['data' => $this->offerOut($this->offers->find($id) ?? [])]];
    }

    private function saveOffer(array $in, ?array $existing): int
    {
        $idVendor = isset($in['id_vendor']) ? (int) $in['id_vendor'] : (int) $this->ctx->idVendor;
        if ($idVendor <= 0) {
            throw new ApiException(422, 'id_vendor is required for admin tokens', 'validation_error');
        }
        $this->ctx->assertCanAccessVendor($idVendor);
        if (!$this->vendors->find($idVendor)) {
            throw new ApiException(422, 'Unknown vendor ' . $idVendor, 'validation_error');
        }
        $idProduct = (int) ($in['id_product'] ?? 0);
        $idAttr = (int) ($in['id_product_attribute'] ?? 0);
        if ($idProduct <= 0 || !\Product::existsInDatabase($idProduct, 'product')) {
            throw new ApiException(422, 'Unknown master product id_product=' . $idProduct, 'validation_error');
        }
        $combinationEan = null;
        if ($idAttr > 0) {
            $combinationEan = \Db::getInstance()->getValue('SELECT ean13 FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $idAttr . ' AND id_product = ' . $idProduct);
            if ($combinationEan === false) {
                throw new ApiException(422, 'id_product_attribute does not belong to the product', 'validation_error');
            }
        } elseif ($existing === null && (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . $idProduct) > 0) {
            throw new ApiException(422, 'Product has combinations: id_product_attribute is required (no variant substitution)', 'validation_error');
        }
        if ($existing === null && !isset($in['price'])) {
            throw new ApiException(422, 'price is required', 'validation_error');
        }
        if (isset($in['price']) && (!is_numeric($in['price']) || (float) $in['price'] < 0)) {
            throw new ApiException(422, 'price must be a non-negative number', 'validation_error');
        }
        if (isset($in['quantity']) && (!is_numeric($in['quantity']) || (int) $in['quantity'] < 0)) {
            throw new ApiException(422, 'quantity must be a non-negative integer', 'validation_error');
        }
        $data = ['id_vendor' => $idVendor, 'id_product' => $idProduct, 'id_product_attribute' => $idAttr];
        foreach (['vendor_sku', 'ean13', 'price', 'discount_price', 'discount_from', 'discount_to', 'quantity', 'condition'] as $f) {
            if (array_key_exists($f, $in)) {
                $data[$f] = $in[$f] === null ? '' : $in[$f];
            }
        }
        if (array_key_exists('active', $in)) {
            $data['active'] = (bool) $in['active'];
        }
        if (empty($data['ean13']) && $existing === null) {
            $ean = $idAttr > 0 ? (string) $combinationEan : (string) \Db::getInstance()->getValue('SELECT ean13 FROM `' . _DB_PREFIX_ . 'product` WHERE id_product = ' . $idProduct);
            if ($ean !== '') {
                $data['ean13'] = $ean;
            }
        }
        try {
            $id = $this->offers->upsert($data);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage(), 'validation_error');
        }
        \Hook::exec('actionBxmpOfferUpdated', ['id_offer' => $id, 'data' => $data, 'source' => 'api']);

        return $id;
    }

    private function offerOut(array $r): array
    {
        return [
            'id_offer' => (int) $r['id_offer'],
            'id_vendor' => (int) $r['id_vendor'],
            'vendor_name' => $r['vendor_name'] ?? null,
            'id_product' => (int) $r['id_product'],
            'id_product_attribute' => (int) $r['id_product_attribute'],
            'vendor_sku' => $r['vendor_sku'],
            'ean13' => $r['ean13'],
            'price' => (float) $r['price'],
            'discount_price' => $r['discount_price'] === null ? null : (float) $r['discount_price'],
            'discount_from' => $r['discount_from'],
            'discount_to' => $r['discount_to'],
            'quantity' => (int) $r['quantity'],
            'condition' => $r['condition'],
            'active' => (bool) $r['active'],
            'date_add' => $r['date_add'],
            'date_upd' => $r['date_upd'],
        ];
    }

    // ------------------------------------------------------------------ price comparison

    private function productOffers(int $idProduct, array $q): array
    {
        if ($idProduct <= 0 || !\Product::existsInDatabase($idProduct, 'product')) {
            throw new ApiException(404, 'Product not found', 'not_found');
        }
        $attr = isset($q['id_product_attribute']) && $q['id_product_attribute'] !== '' ? (int) $q['id_product_attribute'] : null;
        $qty = max(1, (int) ($q['quantity'] ?? 1));
        $dest = (isset($q['id_country']) || isset($q['country_iso']) || isset($q['postcode'])) ? CartOptimizationService::destinationFromArray($q) : null;
        $service = new PriceComparisonService($this->offers, $this->shipping);
        $groups = $service->compareProduct($idProduct, $attr, $qty, $dest);
        $data = [];
        foreach ($groups as $idAttr => $rows) {
            $best = $service->comparator()->best($rows);
            $data[] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $idAttr,
                'best_offer_id' => $best?->offer->idOffer,
                'offers' => array_map(static fn (ComparisonRow $r) => $r->toArray(), $rows),
            ];
        }

        return [200, ['data' => $data, 'meta' => ['quantity' => $qty, 'destination' => $dest?->toArray()]]];
    }

    // ------------------------------------------------------------------ optimizer

    /**
     * Body: {"lines":[{"id_product":1,"id_product_attribute":0,"quantity":1}], "destination":{"country_iso":"IT","postcode":"20121"},
     *        "allow_partial":false,
     *        // optional fully stateless mode (no DB lookup):
     *        "offers":[{"id_offer":1,"id_vendor":1,"id_product":1,"id_product_attribute":0,"price":5,"quantity":10}],
     *        "shipping_policies":[{"id_vendor":1,"name":"Vendor A","rules":[{"base_cost":5,"free_threshold":30}]}]}
     */
    private function optimize(array $body): array
    {
        $rawLines = $body['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === [] || count($rawLines) > self::MAX_LINES) {
            throw new ApiException(422, 'lines must be a non-empty array (max ' . self::MAX_LINES . ')', 'validation_error');
        }
        $lines = [];
        foreach ($rawLines as $i => $l) {
            try {
                $lines[] = new BasketLine((int) ($l['id_product'] ?? 0), (int) ($l['id_product_attribute'] ?? 0), (int) ($l['quantity'] ?? 0));
            } catch (\InvalidArgumentException $e) {
                throw new ApiException(422, sprintf('lines[%d]: %s', $i, $e->getMessage()), 'validation_error');
            }
        }
        $dest = CartOptimizationService::destinationFromArray(is_array($body['destination'] ?? null) ? $body['destination'] : []);
        $allowPartial = !empty($body['allow_partial']);

        try {
            if (isset($body['offers'])) {
                $result = $this->statelessOptimize($lines, $body, $dest, $allowPartial);
                $source = 'request';
            } else {
                $service = new CartOptimizationService($this->offers, $this->shipping, CartOptimizationService::defaultOptimizer($allowPartial));
                $result = $service->optimize($lines, $dest);
                $source = 'catalog';
            }
        } catch (UnavailableProductException $e) {
            throw new ApiException(422, $e->getMessage(), 'unavailable_product', ['lines' => $e->lines]);
        }

        return [200, ['data' => $result->toArray(), 'meta' => ['offers_source' => $source, 'destination' => $dest->toArray()]]];
    }

    /** @param BasketLine[] $lines */
    private function statelessOptimize(array $lines, array $body, Destination $dest, bool $allowPartial): \BrainX\Marketplace\Domain\Optimizer\AllocationResult
    {
        if (!is_array($body['offers'])) {
            throw new ApiException(422, 'offers must be an array', 'validation_error');
        }
        $offers = [];
        foreach ($body['offers'] as $i => $o) {
            try {
                $offers[] = new OfferCandidate((int) ($o['id_offer'] ?? $i + 1), (int) $o['id_vendor'], (int) $o['id_product'], (int) ($o['id_product_attribute'] ?? 0), (float) $o['price'], (int) ($o['quantity'] ?? 0), $o['vendor_sku'] ?? null);
            } catch (\Throwable $e) {
                throw new ApiException(422, sprintf('offers[%d]: %s', $i, $e->getMessage()), 'validation_error');
            }
        }
        $policies = [];
        foreach ((array) ($body['shipping_policies'] ?? []) as $p) {
            $idVendor = (int) ($p['id_vendor'] ?? 0);
            $rules = array_map(static fn ($r) => ShippingRule::fromArray(['id_vendor' => $idVendor] + (array) $r), (array) ($p['rules'] ?? []));
            $policies[] = new ShippingPolicy($idVendor, $rules, isset($p['name']) ? (string) $p['name'] : null);
        }

        return (new CartOptimizer(allowPartial: $allowPartial))->optimize($lines, $offers, $policies, $dest);
    }

    // ------------------------------------------------------------------ vendor orders

    private function listVendorOrders(array $q): array
    {
        $filters = [];
        foreach (['id_vendor', 'id_order'] as $f) {
            if (isset($q[$f]) && $q[$f] !== '') {
                $filters[$f] = (int) $q[$f];
            }
        }
        foreach (['status', 'date_from', 'date_to'] as $f) {
            if (!empty($q[$f])) {
                $filters[$f] = (string) $q[$f];
            }
        }
        foreach (['date_from', 'date_to'] as $f) {
            if (isset($filters[$f]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$f])) {
                throw new ApiException(422, $f . ' must be YYYY-MM-DD', 'validation_error');
            }
        }
        if (!$this->ctx->isAdmin()) {
            if (isset($filters['id_vendor']) && $filters['id_vendor'] !== $this->ctx->idVendor) {
                throw new ApiException(403, 'Token is scoped to another vendor', 'forbidden');
            }
            $filters['id_vendor'] = $this->ctx->idVendor;
        }
        $limit = max(1, min(1000, (int) ($q['limit'] ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $rows = $this->vendorOrders->search($filters, $limit, $offset);
        if (!empty($q['with_lines'])) {
            foreach ($rows as &$r) {
                $r['lines'] = $this->vendorOrders->lines((int) $r['id_vendor_order']);
            }
            unset($r);
        }

        return [200, ['data' => $rows, 'meta' => ['count' => count($rows), 'total' => $this->vendorOrders->count($filters), 'limit' => $limit, 'offset' => $offset]]];
    }

    private function getVendorOrder(int $id): array
    {
        $row = $this->vendorOrders->find($id) ?? throw new ApiException(404, 'Vendor order not found', 'not_found');
        $this->ctx->assertCanAccessVendor((int) $row['id_vendor']);

        return [200, ['data' => $row]];
    }
}
