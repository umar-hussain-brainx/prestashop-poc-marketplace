# bxmpvendorportal: BrainX Marketplace vendor portal (front office)

Covers scope **module 1** (vendor side: role-based vendor dashboards, offers, pricing, inventory, orders, shipments,
commercial data) and **module 8** (private dashboard and business analytics, competitive price positioning).
Requires the core module `bxmarketplace` (schema, ObjectModels, PriceComparator, CommissionCalculator,
ShippingRuleResolver). No core file, override or theme file is modified.

> **Why not `bxmpvendor`?** PHP class names are case-insensitive. A module named `bxmpvendor` must declare the class
> `Bxmpvendor`, which *is* the core ObjectModel `BxmpVendor`. That causes a fatal "Cannot redeclare class" error as soon
> as both are loaded (for example on a back-office vendors page). The module is therefore named `bxmpvendorportal`.

## Install / tests

```bash
docker compose exec -T -u www-data prestashop php bin/console prestashop:module install bxmpvendorportal
docker compose exec -T -u www-data prestashop php modules/bxmpvendorportal/tools/seed_history.php --staff   # dev demo data
cd modules/bxmpvendorportal && composer test      # PHPUnit (pure logic), then `composer build` before committing
cd tests/e2e && NODE_PATH=$(npm root -g) node portal.e2e.js   # Playwright end-to-end checks (writes docs/screenshots/vendor-*.png)
```

## Access and security

Vendors log in as ordinary PrestaShop customers (for example `vendor-a@marketplace.test` / `Vendor123!`). The portal
finds their vendor with `BxmpVendorUser::getMembershipForCustomer()`. The links are "Vendor portal" in My account
(`displayCustomerAccount`) and in the header (`displayNav2`). Both appear only for vendors.

Every request goes through `classes/BxmpVendorPortalController.php`:

1. **Login.** Anonymous visitors are redirected to the login page.
2. **Vendor membership.** A logged-in customer who is not a vendor gets a **403** page.
3. **Page permission.** The role must allow the page (`Domain\RolePolicy`). Each POST action checks its own permission again. If not, **403**.
4. **CSRF.** Each session gets a random token, which is checked on **every POST**. If it is missing or wrong, **403**.
5. **Vendor scoping.** Every query is filtered on the membership's `id_vendor`. A foreign id gets **404**, so the portal never reveals that it exists. A posted `id_vendor` is ignored.

| Permission | owner | manager | staff |
|---|---|---|---|
| dashboard (operational) / orders view + accept/ship/deliver/cancel / orders CSV | ✓ | ✓ | ✓ |
| financial analytics (KPIs, trend, best sellers) | ✓ | ✓ | – |
| offers view | ✓ | ✓ | ✓ (read-only) |
| offers edit / bulk stock / add offer / price positioning / CSV catalog import | ✓ | ✓ | – |
| commercial data, shipping rules, users | ✓ | – | – |

## Pages (`index.php?fc=module&module=bxmpvendorportal&controller=…`, friendly `/module/bxmpvendorportal/<page>`)

- **dashboard**
  - KPI tiles for a chosen period (7, 30 or 90 days, 12 months, or custom dates), each with its change against the previous period of the same length: GMV, net sales after commission, orders, average order value, commission paid, items sold.
  - Sales trend chart by day, week or month. It is drawn as inline SVG, so it needs no CDN.
  - Best-selling products table and orders-by-status breakdown.
  - Staff users see the open-order counters only.
  - The SQL lives in `src/Infrastructure/AnalyticsRepository.php`.
- **offers**
  - Lists, searches (name, EAN, SKU, reference) and paginates the vendor's own offers, filtered by status.
  - Inline edit of price, discount price and discount window, stock, and active flag.
  - Add an offer on an **existing** master product or combination, found by EAN or by name search.
  - Bulk stock update: one `SKU or EAN;qty` per line.
  - Competitive position on every row: rank among sellers, best competitor's effective price, Δ %, and buy-box flag. It uses the core `PriceComparator::vendorPosition()` on identical (product, combination) pairs.
  - Each save goes through the core `OfferRepository::upsert()`, dispatches `actionBxmpOfferUpdated` (source `vendor_portal`) and re-syncs the native `StockAvailable`.
- **positioning**
  - Every own SKU against its competitors, with summary counters (buy box won, not cheapest, sole seller, not ranked).
  - Suggested price to become the cheapest offer (`Domain\SuggestedPrice`): best competitor minus a step (`BXMPV_PRICE_STEP`, default €0.01), or how much the price can rise while staying cheapest.
  - One-click **Apply**, which also removes any running discount.
- **orders**
  - Own `bxmp_vendor_order` rows, filtered by status, dates or reference.
  - Detail view with the order lines and the customer's delivery address, read from the parent PS order.
  - Workflow `pending → accepted → shipped (carrier + tracking required) → delivered`, plus `pending|accepted → cancelled`, which gives the stock back to the offers. See `Domain\OrderWorkflow`.
  - Status changes are concurrency-safe: the UPDATE only applies if the previous status is unchanged.
  - Each change dispatches `actionBxmpVendorOrderStatusUpdate` with `id_vendor_order`, `old_status`, `new_status`, `id_vendor`, `id_order`, `tracking_number`, `carrier_name`, `source` and `id_customer`.
  - The parent PS order is **not modified**. An optional private customer-service note can be added to it (`BXMPV_ORDER_NOTES`).
  - CSV export of the vendor's own orders.
- **profile** (owner only)
  - Editable: company, VAT number, contact email, payout account.
  - Read-only: commission terms.
  - Shipping rules CRUD: country, postcode prefixes and ranges, exclusions, base cost, reduced cost and threshold, free-shipping threshold, priority. Input is validated by `Domain\ShippingRuleInput`.
  - Shipping simulator based on the core `ShippingRuleResolver`.
- **users** (owner only)
  - Add an existing registered customer by email with a role; change roles; deactivate or reactivate users.
  - Guards: an owner cannot lock themselves out, a vendor always keeps at least one active owner, and a customer can be a member of only one vendor.
- **csv** (only if `bxmpcsv` is enabled; otherwise a graceful message)
  - Upload an offers or order-status CSV through `\BrainX\Csv\ImportService` (queued, then processed right away).
  - List the vendor's own import jobs, with job details and error report.
  - Download templates, and download the vendor's own orders through `\BrainX\Csv\OrderExportService`.

Configuration (module **Configure**): `BXMPV_ORDER_NOTES`, `BXMPV_PRICE_STEP`, `BXMPV_PAGE_SIZE`.
Logging: channel `vendor` in `bxmp_log` (writes, denied access, seeder runs).

## Demo history: `tools/seed_history.php` (DEV ONLY)

Real vendor orders come from `bxmpcheckout`. So that the analytics have data before then, the seeder inserts about 90
days of sub-orders (Vendor A ≈ 280, B ≈ 180, C ≈ 120). The data has weekday seasonality, a gentle upward trend, a few
best-selling SKUs and status by age, and commission is computed with the core calculator.

- **Only** `bxmp_vendor_order` and `bxmp_vendor_order_line` rows are written. No PS order, cart, stock or payment is touched.
- `reference` starts with **`HIST-`** (`HIST-VENDOR-A-20261015-02`). This prefix is how every consumer can recognise or exclude these rows.
- `id_order` is a **synthetic id ≥ 4 000 000 000** that matches no PS order. A constant `0` cannot be used because the core table has `UNIQUE(id_order, id_vendor)`.
- The seeder is idempotent: the RNG is seeded per vendor and day, and existing references are skipped.
- Options: `--days=N`, `--vendor=code`, `--purge` (deletes all HIST- rows), `--reset` (purge, then seed again), `--staff` (creates `staff-a@marketplace.test` and `manager-a@marketplace.test` / `Vendor123!` as staff and manager of Vendor A).
- HIST- orders have no parent order, so their detail page shows no delivery address, and cancelling one does not restock.

## Layout

`bxmpvendorportal.php` (hooks, config) · `classes/BxmpVendorPortalController.php` (security base) · `controllers/front/*` ·
`src/Domain` (pure PHP, unit-tested) · `src/Infrastructure` (SQL / PrestaShop-bound) · `views/templates/front` (own layout
extending the theme's `page.tpl`), `views/css/portal.css`, `views/js/portal.js` · `tools/seed_history.php` · `tests/Unit`, `tests/e2e`.
