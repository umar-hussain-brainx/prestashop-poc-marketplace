# bxmpadmin — central administration, analytics, commissions & settlements

Scope modules **7** (vendor-specific commission management: automated calculations and adjustments, settlement reports,
financial reconciliation) and **9** (central administration & advanced analytics, exportable reports, full control over
vendor accounts and commercial conditions). Depends on `bxmarketplace`; **no own tables** — it works on the core
`bxmp_vendor_order(_line)`, `bxmp_commission_adjustment`, `bxmp_settlement`, `bxmp_offer`, `bxmp_vendor` tables plus
native `ps_orders`, `ps_connections`, `ps_cart`.

```bash
docker compose exec -T -u www-data prestashop php bin/console prestashop:module install bxmpadmin
cd modules/bxmpadmin && composer test && composer build   # PHPUnit (Period, ReconciliationChecker, SvgChart), then back to no-dev autoloader
```

## Back office (Sell → Marketplace)

| Tab (class) | What |
|---|---|
| Marketplace dashboard (`AdminBxmpDashboard`) | Period presets (today … 12 months, all, custom) + vendor filter. KPI tiles with delta vs previous period: GMV, commission revenue (net of adjustments) + take rate, vendor payouts, orders / vendor orders / vendors per order, AOV, new vs returning customers, conversion rate (orders / visits from native `ps_connections`), cart abandonment (carts with products and no order), active offers, offers per product, cancellation rate. Inline-SVG GMV/commission trend (day / ISO week / month buckets), GMV share by vendor, status breakdown, **vendor league** (GMV, share, orders, commission, payout, avg order, avg shipping, fulfilment rate, avg hours to ship, cancellation rate, live offers, buy-box share), top products, top categories. |
| Commissions (`AdminBxmpCommissions`) | Commission per vendor order with the vendor's current terms recomputed by the core `CommissionCalculator` (drift column). Banner when a vendor's rate changed → review page → confirmed **recalculation** of not-cancelled, not-yet-settled orders (lines + order commission + payout), draft settlements refreshed, logged in `bxmp_log` (channel `commission`). |
| Commission adjustments (`AdminBxmpAdjustments`) | CRUD of `bxmp_commission_adjustment` (vendor, optional vendor order of that vendor, amount ≠ 0, mandatory reason). `+` = credit to vendor, `−` = deduction. Locked when dated inside an approved/paid settlement. |
| Settlements (`AdminBxmpSettlements`) | Generate draft per vendor (or all vendors) + period → totals from `CommissionCalculator::settle()` (gross − commission + adjustments, cancelled excluded). Workflow **draft → approved → paid** (approved can be reopened to draft; paid is final). Approval freezes the latest figures; overlapping an approved/paid settlement is refused; regenerating refreshes the draft. "Live check" column + detail page (stored vs live figures, transferred amount, orders, adjustments, scoped reconciliation, statement CSV). |
| Reconciliation (`AdminBxmpReconciliation`) | Per vendor: gross vs commissions vs payouts vs adjustments vs net due vs transferred (orders with `payment_transfer_ref`) vs settled. Flags: `total_mismatch`, `payout_mismatch`, `negative_payout`, `missing_transfer_ref`, `cancelled_transferred`, `cancelled_with_payout`, `commission_drift`, `parent_order_mismatch` (Σ vendor orders ≠ PS order products + shipping tax excl.), `settlement_stale`. |
| Commercial conditions (`AdminBxmpConditions`) | All vendors with rate / fixed per line / example commission, shipping rules summary, payout account, live offers, users, orders, approved-unpaid amount; quick activate/deactivate; links to the core vendor edit form, shipping rules and the vendor-filtered dashboard. |

Every table has a **CSV** button (`&export=<key>`; UTF-8 BOM, `;` separator for EU Excel): `kpis`, `trend`, `league`,
`products`, `categories`, `commissions`, `settlements`, `settlement_orders` (statement), `reconciliation`,
`reconciliation_issues`, `conditions`. Exports are logged (channel `admin`).

## Code

| Path | Role |
|---|---|
| `src/Domain/Period.php` | pure: presets, bounds, buckets (day/week/month), previous period |
| `src/Domain/ReconciliationChecker.php` | pure: per-vendor sums + mismatch flags (unit-tested) |
| `src/Domain/SvgChart.php` | pure: dependency-free inline SVG bar/line + horizontal bar charts (works without CDN) |
| `src/Repository/AnalyticsRepository.php` | SQL aggregates for the dashboard (+ buy-box snapshot via core `PriceComparator`) |
| `src/Repository/FinanceRepository.php` | vendor orders with lock status, adjustments, settlements, commercial conditions |
| `src/Service/CommissionService.php` | expected commission under current terms, confirmed recalculation |
| `src/Service/SettlementService.php` | generate / transition / delete draft / refresh drafts / reconcile |
| `src/Export/CsvExporter.php` | CSV streaming |
| `classes/BxmpAdminPageController.php` | base BO page controller (filters, tables, exports, flash messages) |
| `tools/test_fixtures.php` | `create` / `cleanup` temporary `BXADM-T-*` vendor orders for verification when no real orders exist |

## Definitions / POC shortcuts

- All amounts tax excluded (core convention). GMV = Σ vendor order `total_paid` (products + shipping), cancelled excluded.
- "Settled" = vendor order dated inside an approved/paid settlement of its vendor (no link table: periods of a vendor's
  locked settlements cannot overlap, so the date is enough).
- Time to ship uses `date_upd − date_add` of shipped/delivered vendor orders (schema has no `shipped_at`).
- Buy-box share is a current snapshot (lowest effective price among ≥ 2 active vendors on the same product/combination).
- Conversion/visits are shop-wide (PS does not attribute visits to vendors); with a vendor filter, orders are that vendor's.
- Paying a settlement only changes its status (the money movement is the payment splitter's job in `bxmpcheckout`).
