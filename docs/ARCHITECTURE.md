# BrainX Multi-Vendor Marketplace — POC Architecture & Contracts

Platform: **PrestaShop 9.0.3 (stable)**, PHP 8.4, MariaDB 10.11. Clean install, **no core file changes,
no overrides (`/override` stays empty), no third-party multi-vendor modules.** All marketplace behaviour
lives in proprietary modules under `modules/`, using only public hooks, ObjectModel, Db, Symfony services,
ModuleFrontController / ModuleAdminController and the carrier-module API.

## Module map (scope module → PrestaShop module)

| # | Scope module | PrestaShop module | Notes |
|---|---|---|---|
| 1 | Vendor Management | `bxmarketplace` (admin CRUD, roles) + `bxmpvendorportal` (vendor portal) | |
| 2 | CSV Import/Export & Sync | `bxmpcsv` | async job queue + cron |
| 3 | Master Catalog & Matching | `bxmarketplace` (MatchingEngine) + `bxmpcsv` (exception queue UI) | |
| 4 | Price Comparison | `bxmarketplace` (PriceComparator) + `bxmpcheckout` (product-page offer table) | |
| 5 | Smart Cart Optimizer | `bxmarketplace` (CartOptimizer, pure PHP) + `bxmpcheckout` (integration) | |
| 6 | Multi-Vendor Shipping & Checkout | `bxmpcheckout` (carrier module, order split, payment allocation) | |
| 7 | Commission Management | `bxmarketplace` (CommissionCalculator) + `bxmpadmin` (settlements) | |
| 8 | Vendor Dashboard & Analytics | `bxmpvendorportal` | |
| 9 | Central Admin & Analytics | `bxmpadmin` | |
| 10 | Categories & Thematic Collections | `bxmpcollections` | |

`bxmarketplace` is the **core** module: it owns the whole DB schema, ObjectModels, domain services and the
internal JSON API. All other modules declare a dependency on it (`$this->dependencies = ['bxmarketplace']`)
and must not create tables of their own except where noted below.

## Data model (owned by `bxmarketplace`, prefix `ps_bxmp_`)

Master products **are native PrestaShop products** (`ps_product` / `ps_product_attribute`), identified by EAN13
(primary match key), then reference/MPN + brand. Vendor offers are a **separate model**.

```
bxmp_vendor            id_vendor PK, name, code (unique slug), email, company, vat_number, active,
                       commission_rate DECIMAL(5,2) (percent), commission_fixed DECIMAL(20,6) (per order line, EUR),
                       payout_account (Mangopay wallet id / IBAN ref), date_add, date_upd
bxmp_vendor_user       id_vendor_user PK, id_vendor, id_customer (PS customer used for FO login),
                       role ENUM('owner','manager','staff'), active
bxmp_offer             id_offer PK, id_vendor, id_product, id_product_attribute (0 = none),
                       vendor_sku, ean13, price DECIMAL(20,6) (tax excl.), discount_price DECIMAL(20,6) NULL,
                       discount_from DATETIME NULL, discount_to DATETIME NULL, quantity INT,
                       condition VARCHAR(16) default 'new', active TINYINT, date_add, date_upd
                       UNIQUE(id_vendor, id_product, id_product_attribute)
bxmp_shipping_rule     id_shipping_rule PK, id_vendor, id_country (0 = all), zipcode_pattern VARCHAR(255) NULL
                       (comma list of prefixes / ranges e.g. "20,21,00100-00199"; NULL = all),
                       excluded TINYINT (1 = vendor does NOT deliver here), base_cost DECIMAL(20,6),
                       free_threshold DECIMAL(20,6) NULL, reduced_threshold DECIMAL(20,6) NULL,
                       reduced_cost DECIMAL(20,6) NULL, priority INT
bxmp_match_exception   id_match_exception PK, id_vendor, id_import_job NULL, vendor_sku, ean13, brand, title,
                       payload JSON/TEXT, reason VARCHAR(64), candidates TEXT (JSON of id_product list),
                       status ENUM('pending','resolved','rejected'), id_product_resolved NULL, date_add, date_upd
bxmp_import_job        id_import_job PK, id_vendor, type ENUM('offers','order_status'), source ENUM('upload','url','path'),
                       file_path, status ENUM('queued','running','done','failed'), rows_total, rows_ok, rows_error,
                       log TEXT, date_add, date_start, date_end
bxmp_feed              id_feed PK, id_vendor, url_or_path, schedule_minutes INT, last_run DATETIME NULL, active
bxmp_cart_allocation   id_cart_allocation PK, id_cart, id_product, id_product_attribute, id_offer, id_vendor,
                       quantity, unit_price DECIMAL(20,6), date_add
bxmp_cart_shipping     id_cart, id_vendor, shipping_cost DECIMAL(20,6), PRIMARY(id_cart,id_vendor)
bxmp_vendor_order      id_vendor_order PK, id_order (PS parent order), id_vendor, reference,
                       total_products DECIMAL(20,6), total_shipping DECIMAL(20,6), total_paid DECIMAL(20,6),
                       commission_amount DECIMAL(20,6), vendor_payout DECIMAL(20,6),
                       status ENUM('pending','accepted','shipped','delivered','cancelled'), tracking_number,
                       carrier_name, payment_transfer_ref, date_add, date_upd
bxmp_vendor_order_line id_vendor_order_line PK, id_vendor_order, id_offer, id_product, id_product_attribute,
                       product_name, vendor_sku, quantity, unit_price DECIMAL(20,6), commission_amount DECIMAL(20,6)
bxmp_commission_adjustment id_adjustment PK, id_vendor, id_vendor_order NULL, amount DECIMAL(20,6), reason, date_add
bxmp_settlement        id_settlement PK, id_vendor, period_from DATE, period_to DATE, gross DECIMAL(20,6),
                       commission DECIMAL(20,6), adjustments DECIMAL(20,6), net_payout DECIMAL(20,6),
                       status ENUM('draft','approved','paid'), date_add
bxmp_collection        id_collection PK, name, slug, description, active, date_start DATETIME NULL,
                       date_end DATETIME NULL, position, date_add, date_upd
bxmp_collection_product id_collection, id_product, position  PRIMARY(id_collection,id_product)
bxmp_collection_category id_collection, id_category  PRIMARY(id_collection,id_category) (all products of category included)
bxmp_log               id_log PK, channel VARCHAR(32), level VARCHAR(16), message TEXT, context TEXT, date_add
bxmp_api_token         id_api_token PK, token CHAR(64) unique, label, id_vendor NULL (NULL = admin scope), active, date_add
```

All money is **tax excluded** inside the engine; PS handles taxes on the parent order.

## Core services (namespace `BrainX\Marketplace\`, PSR-4 at `modules/bxmarketplace/src/`)

Pure-PHP (no PrestaShop dependency, unit-tested with PHPUnit in `modules/bxmarketplace/tests/`):

- `Domain\Optimizer\CartOptimizer` — `optimize(BasketLine[] $lines, OfferCandidate[] $offers, ShippingPolicy[] $vendorPolicies, Destination $dest): AllocationResult`.
  Exact match on (id_product, id_product_attribute) only — **never substitutes**. Handles quantity split,
  stock, thresholds (free / reduced), destination/postcode exclusion. Exact branch-and-bound for small
  baskets, plus local-search heuristic fallback for large ones. Must return €35 for the client scenario.
- `Domain\Pricing\PriceComparator` — effective price (discount window), availability, delivery eligibility, ranking.
- `Domain\Matching\MatchingEngine` — match vendor row → master product (EAN exact → reference+brand → normalized
  title similarity); returns `MatchResult{status: matched|ambiguous|new|conflict, id_product, candidates, reason}`.
- `Domain\Commission\CommissionCalculator` — percent + fixed per line, adjustments, settlement totals.
- `Domain\Shipping\ShippingRuleResolver` — picks the applicable rule for vendor + country + zip and computes cost for a subtotal.

PrestaShop-bound (`Infrastructure\`): repositories built on `Db::getInstance()`, `Logger` writing `bxmp_log`,
`Api\Router` serving `index.php?fc=module&module=bxmarketplace&controller=api&resource=...` with Bearer token
(`bxmp_api_token`). API documented in `docs/API.md` (OpenAPI 3 in `docs/openapi.yaml`).

### Implementation notes (core module as built — see `modules/bxmarketplace/README.md` for the full public API)

- **Autoload**: `require_once _PS_MODULE_DIR_ . 'bxmarketplace/vendor/autoload.php'` gives PSR-4 `BrainX\Marketplace\`
  plus a classmap of the ObjectModels in `classes/` (`BxmpVendor`, `BxmpVendorUser`, `BxmpOffer`, `BxmpShippingRule`,
  `BxmpMatchException`, `BxmpImportJob`, `BxmpFeed`, `BxmpCartAllocation`, `BxmpVendorOrder`, `BxmpVendorOrderLine`,
  `BxmpCommissionAdjustment`, `BxmpSettlement`, `BxmpCollection`, `BxmpApiToken`). `vendor/` holds only the no-dev autoloader.
- **Optimizer value objects** live in `Domain\Optimizer\`: `BasketLine`, `OfferCandidate` (effective unit price + stock),
  `ShippingPolicy` (vendor id + `Domain\Shipping\ShippingRule[]`), `Destination` (id_country, postcode),
  `AllocationResult` (`VendorAllocation[]` → `AllocatedLine[]`, totals, mode exact|heuristic, alternatives, explanation),
  `UnavailableProductException`. Exact B&B when ≤ 12 distinct lines (configurable `BXMP_OPT_EXACT_MAX_LINES`), else a
  time-boxed (1.5 s) multi-start local search.
- **Shipping semantics**: a vendor with no rules ships free everywhere; a vendor with rules but none matching does not
  deliver; highest `priority` wins, then most specific (zip > country > all); an `excluded` winner = no delivery.
- **Facades for satellite modules**: `Infrastructure\Service\CartOptimizationService` (`optimize()`, `optimizeCart(Cart)`)
  and `Infrastructure\Service\PriceComparisonService`; `Repository\CartAllocationRepository::save($idCart, $result)`
  persists `bxmp_cart_allocation` + `bxmp_cart_shipping`.
- **Matching**: EAN check digit is validated but, by default, an EAN with a bad check digit is still used for an exact
  match (reason `ean_exact_checksum_warning`) — the client demo EANs 8000000000011/8000000000028 are not GS1-valid.
  Title similarity never auto-matches by default (goes to the exception queue as `ambiguous`).
- **Custom hooks** created by the core: `actionBxmpCartOptimized`, `actionBxmpOfferUpdated` (dispatched by core),
  `actionBxmpVendorOrderCreated` (bxmpcheckout dispatches), `actionBxmpVendorOrderStatusUpdate` (bxmpvendorportal/bxmpadmin dispatch).
- **Back-office tabs** (parent `AdminBxMarketplace` under SELL): `AdminBxmpVendors`, `AdminBxmpVendorUsers`,
  `AdminBxmpShippingRules`, `AdminBxmpOffers`; API tokens are managed in the module configuration page.
- **API resources**: `me`, `vendors[/{id}]`, `offers[/{id}]` (GET/POST upsert/PATCH), `products/{id}/offers`,
  `cart/optimize` (catalog or fully stateless with inline offers/policies), `vendor-orders[/{id}]`.
- **Demo data**: `php modules/bxmarketplace/tools/seed_demo.php` (run as www-data inside the container, idempotent).

## Integration approach (no core modifications)

- **Pricing in cart**: `bxmpcheckout` runs the optimizer on `actionCartSave`, stores `bxmp_cart_allocation`
  and writes a **cart-scoped `SpecificPrice`** (`id_cart` set) per line = blended allocated unit price.
- **Shipping**: `bxmpcheckout` is a **carrier module** (`CarrierModule::getOrderShippingCost`) returning the sum
  of per-vendor shipping from `bxmp_cart_shipping`.
- **Order split**: one customer payment → one PS parent order; on `actionValidateOrder` the allocation is
  materialised into `bxmp_vendor_order` (+lines), commissions computed, and a `PaymentSplitter` (interface
  with `MangopayPaymentSplitter` stub/sandbox adapter + `NullPaymentSplitter`) allocates funds per vendor.
- **Vendor login**: vendors are PS customers linked through `bxmp_vendor_user`; the portal is a set of
  `ModuleFrontController`s with role checks.

## Conventions

- PHP 8.1+ syntax compatible, `declare(strict_types=1)` in `src/`, PSR-12.
- Each module has `composer.json` (PSR-4 autoload, `vendor/` committed for zero-step install) or a manual `spl_autoload_register`.
- Install/uninstall must be idempotent. Core `install()` runs `sql/install.php`.
- Admin tabs under a parent tab `AdminBxMarketplace` ("Marketplace") created by core.
- Logging through `BrainX\Marketplace\Infrastructure\Logger`.
- Tests: `composer test` per module (PHPUnit 10/11) for pure logic; integration checks via PS in docker.

## Local environment

`docker compose up -d` → shop at http://localhost:8080, back office http://localhost:8080/admin-dev
(admin@marketplace.test / Admin123!). DB on localhost:3307 (prestashop/prestashop).
Run PHP inside the container: `docker compose exec prestashop php bin/console ...`.
