# bxmarketplace — BrainX Marketplace core module

Core of the multi-vendor marketplace POC (PrestaShop 9.0.x, PHP ≥ 8.1). Owns the whole `bxmp_*` schema, the
ObjectModels, the pure-PHP domain engines, the internal JSON API and the "Marketplace" back-office menu.
Binding contract: [`docs/ARCHITECTURE.md`](../../docs/ARCHITECTURE.md). API: [`docs/API.md`](../../docs/API.md).

## Install / demo data / tests

```bash
docker compose exec -T -u www-data prestashop php bin/console prestashop:module install bxmarketplace
docker compose exec -T -u www-data prestashop php modules/bxmarketplace/tools/seed_demo.php   # idempotent, prints API tokens
cd modules/bxmarketplace && composer test     # installs phpunit (dev) + runs the domain suite
composer build                                # back to the committed no-dev autoloader before committing
```

`vendor/` is committed but contains **only** the generated no-dev autoloader (`.gitignore` keeps dev packages out).

## Using the core from another module

```php
// in your module main file / controllers
require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php';   // PSR-4 BrainX\Marketplace\ + classmap classes/
public function __construct() { ...; $this->dependencies = ['bxmarketplace']; }
```

### ObjectModels (`classes/`, global namespace, one per main table)

| Class | Table | Helpers |
|---|---|---|
| `BxmpVendor` | `bxmp_vendor` | `getIdByCode($code)`, `getAll($activeOnly)`, `getNames()`; `delete()` cascades users/offers/rules/feeds/tokens |
| `BxmpVendorUser` | `bxmp_vendor_user` | `ROLE_OWNER/MANAGER/STAFF`, `getMembershipForCustomer($idCustomer)` → `{id_vendor, role, vendor_name, ...}`, `findId()`, `roleAtLeast($role, $required)` |
| `BxmpOffer` | `bxmp_offer` | `findId($idVendor, $idProduct, $idAttr)`, `getForProduct($idProduct, $idAttr = null)` |
| `BxmpShippingRule` | `bxmp_shipping_rule` | `getForVendor($idVendor)` |
| `BxmpMatchException` | `bxmp_match_exception` | |
| `BxmpImportJob` | `bxmp_import_job` | |
| `BxmpFeed` | `bxmp_feed` | |
| `BxmpCartAllocation` | `bxmp_cart_allocation` | (bulk writes: `CartAllocationRepository`) |
| `BxmpVendorOrder` | `bxmp_vendor_order` | `STATUSES`, `getLines()`, `getIdsByOrder($idOrder)` |
| `BxmpVendorOrderLine` | `bxmp_vendor_order_line` | |
| `BxmpCommissionAdjustment` | `bxmp_commission_adjustment` | amount > 0 = credit to vendor, < 0 = deduction |
| `BxmpSettlement` | `bxmp_settlement` | |
| `BxmpCollection` | `bxmp_collection` | `isLive($now)`, `getProductIds()`, `getCategoryIds()` |
| `BxmpApiToken` | `bxmp_api_token` | `issue($label, $idVendor = null)`, `generateToken()` |

Composite-key tables (`bxmp_cart_shipping`, `bxmp_collection_product`, `bxmp_collection_category`) and `bxmp_log` are
accessed with `Db` / repositories / `Logger`.

### Domain (pure PHP, no PrestaShop dependency — `src/Domain`)

```php
use BrainX\Marketplace\Domain\Optimizer\{CartOptimizer, BasketLine, OfferCandidate, ShippingPolicy, Destination};
use BrainX\Marketplace\Domain\Shipping\ShippingRule;

$result = (new CartOptimizer())->optimize(
    [new BasketLine(idProduct: 20, idProductAttribute: 0, quantity: 1), ...],
    [new OfferCandidate(idOffer: 1, idVendor: 1, idProduct: 20, idProductAttribute: 0, unitPrice: 5.0, availableQuantity: 50), ...],
    [ShippingPolicy::flat(1, baseCost: 5.0, freeThreshold: 30.0, vendorName: 'Vendor A'),
     new ShippingPolicy(3, [new ShippingRule(3, 6.90, 60.0, 25.0, 2.90), new ShippingRule(3, 0, null, null, null, countryId: 10, zipcodePattern: '09', excluded: true, priority: 10)])],
    new Destination(countryId: 10, postcode: '20121'),
);   // throws UnavailableProductException (->lines) unless new CartOptimizer(allowPartial: true)

$result->total(); $result->itemsTotal; $result->shippingTotal; $result->mode /* exact|heuristic */; $result->provenOptimal;
$result->vendors;             // VendorAllocation[]: idVendor, lines (AllocatedLine[]: idOffer, quantity, unitPrice...), subtotal, shippingCost, shippingTier
$result->lines();             // flat AllocatedLine[]
$result->blendedUnitPrices(); // "idProduct-idAttr" => qty-weighted unit price (for cart-scoped SpecificPrice)
$result->alternatives; $result->explanation; $result->excludedOffers; $result->unavailable; $result->toArray();
```

`CartOptimizer` constructor knobs: `exactMaxLines` (12), `maxCompositionsPerLine` (2000), `nodeBudget` (400 000),
`allowPartial` (false), `maxStarts` (12), `heuristicTimeBudgetMs` (1500). Exact branch-and-bound for ≤ 12 distinct
lines (measured: 12 lines × 8 vendors ≈ 0.13 s, proven optimal), time-boxed multi-start local search beyond
(30 lines × 5 vendors ≈ 0.1 s; 100 lines × 30 vendors ≈ 1.5 s). Never substitutes: offers are matched on the exact
`(id_product, id_product_attribute)`.

| Service | Key methods |
|---|---|
| `Domain\Pricing\PriceComparator` | `effectivePrice(OfferSnapshot, ?now)`, `isDiscountActive()`, `compare(OfferSnapshot[], qty, ?Destination, ShippingPolicy[], ?now): ComparisonRow[]`, `best(rows)`, `vendorPosition(rows, idVendor)` (dashboard positioning), `toCandidates(OfferSnapshot[], ?now): OfferCandidate[]` |
| `Domain\Pricing\OfferSnapshot` | `fromArray(bxmp_offer row)` |
| `Domain\Matching\MatchingEngine` | `new MatchingEngine(CatalogLookup, titleThreshold=0.6, autoMatchTitle=false, autoTitleThreshold=0.95, strictEanChecksum=false)`, `match(VendorRow): MatchResult` (`status` matched/ambiguous/new/conflict, `idProduct`, `idProductAttribute`, `candidates`, `reason`, `needsReview()`, `candidateIds()`), static `normalizeEan()`, `isValidEan13()`, `ean13CheckDigit()`, `titleSimilarity()` |
| `Domain\Matching\VendorRow` / `MasterProduct` / `InMemoryCatalog` / `CatalogLookup` | `VendorRow::fromArray(['ean13'|'ean','reference'|'mpn','brand','title'|'name','vendor_sku'|'sku'])` |
| `Domain\Commission\CommissionCalculator` | `lineCommission(unit, qty, CommissionTerms)`, `calculateOrder(lines[{unit_price,quantity}], shipping, terms): OrderCommission` (`commission`, `totalPaid()`, `vendorPayout()`, `lineCommissions`), `settle(vendorOrders[], adjustments[]): SettlementTotals` (`netPayout()`), `splitPayment([idVendor => OrderCommission])` |
| `Domain\Commission\CommissionTerms` | `new CommissionTerms(ratePercent, fixedPerLine, applyOnShipping)`, `fromVendorRow(row)` |
| `Domain\Shipping\ShippingRuleResolver` | `resolve(rules, Destination)`, `canDeliver()`, `effectiveRule()`, `costFor(rules, dest, subtotal): ?float`, static `cost(rule, subtotal)`, `tier()`, `zipMatches(pattern, zip)` |

Shipping semantics: vendor without rules = delivers everywhere for free; vendor with rules but none matching = does
not deliver; winner = highest `priority`, then most specific (zip > country > all); `excluded` winner = no delivery.

### Infrastructure (PrestaShop-bound — `src/Infrastructure`)

| Class | Key methods |
|---|---|
| `Infrastructure\Logger` | `Logger::channel('csv')->info($msg, $ctx)` / `warning` / `error` / `debug`; `Logger::write($channel, $level, $msg, $ctx)`; `Logger::latest($limit, $channel)` |
| `Infrastructure\Service\CartOptimizationService` | `optimize(BasketLine[]|array[], Destination, ?now): AllocationResult`, `optimizeCart(Cart, ?Destination)`, static `destinationFromAddress($idAddress)`, `destinationFromArray(['country_iso'|'id_country','postcode'])`, `defaultOptimizer($allowPartial)` |
| `Infrastructure\Service\PriceComparisonService` | `compareProduct($idProduct, ?$idAttr, $qty, ?Destination): [idAttr => ComparisonRow[]]`, `bestOffer(...)` |
| `Repository\VendorRepository` | `findAll()`, `find()`, `findByIds()`, `commissionTerms($idVendor)`, `users($idVendor)`, `membershipForCustomer($idCustomer)` |
| `Repository\OfferRepository` | `find()`, `search($filters, $limit, $offset)`, `count()`, `findForProduct()`, `snapshotsForPairs([[idP, idA]])`, `upsert(array)` (validates, returns id), `decrementStock($idOffer, $qty)`, `totalStock($idP, $idA)` |
| `Repository\ShippingRuleRepository` | `findByVendor()`, `policiesFor(?vendorIds): [idVendor => ShippingPolicy]` |
| `Repository\VendorOrderRepository` | `search($filters)`, `count()`, `find($id)` (+lines), `lines($id)` |
| `Repository\CartAllocationRepository` | `save($idCart, AllocationResult)` (replaces `bxmp_cart_allocation` + `bxmp_cart_shipping`), `clear()`, `allocation()`, `shipping()`, `totalShipping()` |
| `Repository\ApiTokenRepository` | `findActive($token)`, `all()`, `findByLabel()`, `issue($label, ?$idVendor)`, `revoke($id)` |
| `Repository\CatalogRepository` | `CatalogLookup` on native `product` / `product_attribute` (EAN, reference/MPN, title) — feed it to `MatchingEngine` |
| `Api\Router`, `Api\ApiContext`, `Api\ApiException` | API dispatch (see docs/API.md) |

## Hooks

| Hook | Kind | Params |
|---|---|---|
| `actionProductDelete` | registered (native) | deletes the product's offers |
| `actionBxmpCartOptimized` | created + dispatched by `CartOptimizationService::optimize()` | `lines`, `destination`, `result` |
| `actionBxmpOfferUpdated` | created + dispatched by API offer writes and BO offer saves | `id_offer`, `data`, `source` (`api`/`admin`) |
| `actionBxmpVendorOrderCreated` | created; **to be dispatched by bxmpcheckout** | `id_vendor_order`, `id_order`, `id_vendor` |
| `actionBxmpVendorOrderStatusUpdate` | created; **to be dispatched by bxmpvendor / bxmpadmin** | `id_vendor_order`, `old_status`, `new_status` |

ObjectModel saves also fire the native `actionObjectBxmp<Class>AddAfter/UpdateAfter/DeleteAfter` hooks.

## Back office

Menu **Sell → Marketplace** (`AdminBxMarketplace`, redirects to Vendors): `AdminBxmpVendors` (CRUD incl. commission
rate / fixed / active, users & shipping summary), `AdminBxmpVendorUsers` (link a PS customer by email + role),
`AdminBxmpShippingRules`, `AdminBxmpOffers`. Other modules add their tabs under parent class `AdminBxMarketplace`.
Module **Configure** page: API tokens (issue / revoke) + optimizer & commission settings
(`BXMP_OPT_EXACT_MAX_LINES`, `BXMP_OPT_NODE_BUDGET`, `BXMP_COMMISSION_ON_SHIPPING`).

## Demo data (`tools/seed_demo.php`)

Vendors A (10 %), B (12.5 %), C (8 % + €0.50/line) with owner logins `vendor-a|b|c@marketplace.test` / `Vendor123!`;
shipping A €5 free ≥ €30, B €5 free ≥ €20, C €6.90 / €2.90 ≥ €25 / free ≥ €60, C excludes IT postcodes `09*`;
"Product 1" (EAN 8000000000011) and "Product 2" (EAN 8000000000028) with the client scenario offers (A €5/€40,
B €20/€25, C €22/€45); multi-vendor offers on 10 PS demo products (EANs set on products/combinations); API tokens;
customer `customer@marketplace.test` / `Customer123!` (Milano 20121). Exits 0 only if the scenario optimises to €35.

Note: the client demo EANs 8000000000011 / 8000000000028 do not carry a valid GS1 check digit; `MatchingEngine` still
matches them exactly (reason `ean_exact_checksum_warning`) unless `strictEanChecksum` is enabled. Generated EANs for the
PS demo products are GS1-valid.
