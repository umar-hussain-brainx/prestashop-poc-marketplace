# bxmpcheckout — multi-vendor shipping & checkout

Scope modules **4** (storefront price comparison), **5** (CartOptimizer integration) and **6** (multi-vendor shipping,
order split, payment allocation) of the BrainX marketplace POC. Depends on `bxmarketplace` (schema + engines);
**no PrestaShop core change, no override, classic theme untouched** (hooks only).

```bash
docker compose exec -T -u www-data prestashop php bin/console prestashop:module install bxmpcheckout
docker compose exec -T -u www-data prestashop php modules/bxmpcheckout/tools/setup_demo.php   # default carrier + bank wire
cd modules/bxmpcheckout && composer test && composer build      # unit tests, then back to the no-dev autoloader
node modules/bxmpcheckout/tests/e2e/checkout.e2e.js              # from repo root, stack running (Playwright, headless)
```

## How it works (no core change)

| Concern | Mechanism |
|---|---|
| Item prices | `actionCartSave` (fired by `Cart::add/update`, i.e. after every add/qty/delete/address change) + `actionFrontControllerSetMedia` (re-price at the start of each storefront request) → `CartSynchronizer` → core `CartOptimizationService::optimize()` → `CartAllocationRepository::save()` → **one cart-scoped native `SpecificPrice` per (product, combination)**: `id_cart` set, `from_quantity 1`, `reduction 0`, fixed `price` = blended allocated unit price (tax excl.), exact `id_product_attribute` (so combination impacts are not added). PS's own `SpecificPrice::getSpecificPrice()` orders by `id_product_attribute DESC, id_cart DESC`, so this row wins over catalog prices; the native cart, totals, taxes, vouchers and order details are then computed by PrestaShop itself. Only diffs are written; in-request caches (`SpecificPrice`, `Product` price cache, `Cart` static caches, `getPackageShippingCost_*`, `presentedCart_*`) are flushed after a change. Per-request memo (signature of lines + destination) + re-entrancy guard prevent loops. |
| Shipping | Carrier module (`extends CarrierModule`): carrier *Marketplace multi-vendor shipping* (`is_module`, `shipping_external`, `need_range`, price range 0→∞ at €0 in every zone, all groups, no tax, no handling). `getOrderShippingCost()` returns Σ per-vendor shipping of the allocation for the cart delivery address (country + postcode), `false` when a line cannot be supplied. `actionFilterDeliveryOptionList` hides every other carrier (config `BXMPCO_EXCLUSIVE_CARRIER`, default on) because they ignore vendor rules. |
| Unavailable / insufficient stock | Optimizer throws `UnavailableProductException` (never substitutes) → allocation and cart prices dropped, `actionPresentCart` sets the cart's native `minimalPurchaseRequired` message ⇒ "Proceed to checkout" disabled, `OrderController` redirects `/order` back to the cart, carrier returns `false`, `actionValidateStepComplete` refuses the delivery step. |
| Order split | `actionValidateOrder` → `VendorOrderCreator`: one `bxmp_vendor_order` per vendor (`<PARENTREF>-V<id_vendor>`) + lines from `bxmp_cart_allocation`, shipping from `bxmp_cart_shipping`, commission via core `CommissionCalculator` + vendor `CommissionTerms` (`BXMP_COMMISSION_ON_SHIPPING` honoured), payout, vendor offer stock decrement, `actionBxmpVendorOrderCreated` per vendor order. Re-optimises (with a warning) if the stored allocation no longer matches the ordered quantities. Idempotent per order. `Order::add()` (core) deletes the cart-scoped specific prices. |
| Payment allocation | `PaymentSplitter` interface → `MangopayPaymentSplitter` (PayIn of the whole payment to an escrow wallet — `payins/bankwire/direct` for bank wire, `payins/card/web` otherwise — then one `transfers` call per vendor wallet with `DebitedFunds` = vendor gross, `Fees` = commission; amounts in cents, idempotency keys) or `NullPaymentSplitter`; `BXMPCO_PAYMENT_SPLITTER`. No credentials ⇒ `sandbox_simulation`: `SimulatedMangopayTransport` answers with deterministic `SIM-…` ids; payloads + responses logged in `bxmp_log` (channel `payment`), refs stored in `bxmp_vendor_order.payment_transfer_ref` (`mangopay-sim:SIM-TR-…|SIM-PAYIN-…`). A real HTTP transport only has to implement `MangopayTransport`. |
| Storefront | `displayProductAdditionalInfo`: vendor offers table (effective price incl. discount window, stock, shipping rule summary for the visitor's destination, 1-piece landed total, *best total* badge) from core `PriceComparisonService`. `displayShoppingCartFooter`: *Optimized allocation* (vendor sub-baskets, subtotal, shipping, free/reduced threshold progress, total, savings, alternatives evaluated by the optimizer). `displayBeforeCarrier`: compact version in the shipping step. `displayOrderConfirmation` / `displayOrderDetail`: vendor split with per-vendor status & tracking. CSS: `views/css/front.css` (responsive). |
| Back office | `displayAdminOrderMain`: vendor split panel (lines, paid, commission, payout, payment ref) with a per-vendor status / carrier / tracking form → hidden tab `AdminBxmpCheckoutVendorOrders` (dispatches `actionBxmpVendorOrderStatusUpdate`). Module *Configure* page: exclusive carrier, alternatives display, payment splitter + Mangopay settings, "repair carrier". |

## Code map

- `bxmpcheckout.php` — carrier module + hooks + config page.
- `src/Checkout/` — `CartSynchronizer` (PS), `SpecificPriceWriter` (PS), `SyncState`; pure: `CartPricePlan` (blended prices + rounding report), `AllocationPresenter` (view model, threshold hints, savings).
- `src/Order/` — `VendorOrderCreator` (PS); pure `VendorOrderPlanner` / `VendorOrderPlan`.
- `src/Payment/` — `PaymentSplitter`, `PaymentSplitRequest`, `VendorShare`, `PaymentSplitResult`, `NullPaymentSplitter`, `PaymentSplitterFactory`, `Mangopay/` (`MangopayPayloadBuilder`, `MangopayPaymentSplitter`, `MangopayTransport`, `SimulatedMangopayTransport`, `MangopayConfig`).
- `src/Carrier/CarrierInstaller.php` — idempotent carrier creation (tracked by `id_reference`, config `BXMPCO_CARRIER_REFERENCE`).
- `tests/Unit` (PHPUnit, pure logic), `tests/e2e/checkout.e2e.js` (Playwright, screenshots in `docs/screenshots/checkout-*.png`).

## Caveats / POC shortcuts

- **Blended unit price**: when one line is split across vendors (e.g. 3 × €22.23 + 2 × €26.77) PS holds a single unit
  price (24.046). With the shop's `PS_ROUND_TYPE = 2` (round per line, the PS default) the line total is exact
  (120.23); with *round per item* it would drift by up to qty × €0.005 (here +€0.02). `CartPricePlan::roundingReport()`
  quantifies it; the vendor split always uses the exact allocated prices and logs a warning if the PS order differs.
  The cart line shows the blended unit price (€24.05), the allocation block shows the real per-vendor prices.
- **Tax**: the engine and vendor orders are tax excluded; PrestaShop applies the product's tax rule on the parent
  order. Shipping is tax-free (carrier without tax rules group). VAT paid above Σ vendor gross stays in escrow
  (`residual_in_escrow`) — who collects/remits VAT per vendor must be decided before go-live.
- **One package**: the carrier prices the whole cart; PS "multi-carrier / split order by carrier" is not used (it would
  create one PS order per carrier and cannot express per-vendor thresholds); one parent order + vendor sub-orders is
  the chosen model. Products restricted to specific carriers would break that assumption.
- **Catalog price vs offers**: the PS product price/discount shown above the offers table is the native catalog price
  (maintained elsewhere, e.g. by the CSV sync); the cart always uses the vendor offers.
- Products without any vendor offer cannot be checked out (treated as "not offered"); first-party sales are out of scope.
- Cart-scoped specific prices of abandoned carts remain until the cart is deleted (cleaned on uninstall); a periodic
  purge of `specific_price` rows whose cart is older than N days would be added in production.
- Mangopay calls run at order validation (simulation); in production transfers should wait for PayIn success
  (bank wire: `PAYIN_NORMAL_SUCCEEDED` webhook) and payouts for delivery.
- Re-pricing at every storefront request is cheap for small carts (exact search ≤ 12 lines ≈ ms); for very large
  carts the per-request memo could be persisted (e.g. signature column) to skip the optimizer.
