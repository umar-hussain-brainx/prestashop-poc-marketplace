# bxmpcollections — custom categories & scheduled thematic collections

Scope module **10**: custom category management, cross-category thematic collections (Christmas, Easter, Halloween,
Carnival, All Saints' Day, Breakfast, Aperitivo…), scheduled activation and deactivation. Depends on `bxmarketplace`
(collections live in the core tables `bxmp_collection`, `bxmp_collection_product`, `bxmp_collection_category`).

```bash
docker compose exec -T -u www-data prestashop php bin/console prestashop:module install bxmpcollections   # seeds demo collections if none
docker compose exec -T -u www-data prestashop php modules/bxmpcollections/tools/seed_collections.php       # re-seed / refresh windows (idempotent)
docker compose exec -T -u www-data prestashop php modules/bxmpcollections/tools/cron.php [--dry-run]       # schedule runner
cd modules/bxmpcollections && composer test && composer build                                              # PHPUnit (ScheduleResolver)
NODE_PATH=$(npm root -g) node modules/bxmpcollections/tests/e2e/collections.e2e.js docs/screenshots        # Playwright checks + screenshots
```

## Scheduling

`BrainX\Collections\Domain\ScheduleResolver` is the single rule used everywhere (FO hooks, landing page, BO list, cron):
**live ⇔ active AND (date_start null OR now ≥ date_start) AND (date_end null OR now ≤ date_end)**; statuses
`live | scheduled | expired | disabled`. It is evaluated at render time, so the shop is correct without cron.
The cron (`tools/cron.php` or `index.php?fc=module&module=bxmpcollections&controller=cron&token=<BXMPCOL_CRON_TOKEN>`,
URL shown in the BO) materialises the status into `bxmp_collection_state`, logs every activation/deactivation in
`bxmp_log` (channel `collections`), dispatches the custom hook `actionBxmpCollectionStatusChange` and clears the module
template cache. Saving a collection in the BO runs it too.

## Back office (Sell → Marketplace)

- **Collections** (`AdminBxmpCollections`): list with schedule status + next change, "Run schedule now"; form (name, slug
  auto/unique, rich description, enabled, visible from/until with window validation, position). Edit page: product search
  by name / reference / EAN13 (product or combination) / id, add (single or multi), remove, reorder; whole categories
  (category tree, sub-categories included); preview of resolved products (explicit first, then categories; active and
  visible only) and a FO link (a signed `preview` token opens non-live collections for admins, `noindex`).
- **Custom categories** (`AdminBxmpCategories`): marketplace category tree via the native `Category` ObjectModel —
  create, rename, move (no move under own subtree), activate/deactivate (root/home protected, customer groups kept);
  **vendor category label → PS category mapping** with a "resolve" tester.

## Front office

- `/collection/{slug}` (hook `moduleRoutes`, `controllers/front/collection.php`): products presented with
  `ProductAssembler` + `ProductListingPresenter` and rendered with the theme's `catalog/_partials/miniatures/product.tpl`,
  24 per page, breadcrumb, canonical; non-live → HTTP 404.
- `/collections`: index of live collections.
- `displayHome`: "Our collections" cards (4 product thumbnails each); `displayNavFullWidth`: menu strip under the header.

## Own tables (`sql/install.php`, dropped on uninstall)

| Table | Columns | Use |
|---|---|---|
| `bxmp_category_map` | id_category_map, id_vendor (0 = all vendors), label, label_key (normalised), id_category, date_add, date_upd; UNIQUE(id_vendor, label_key) | vendor feed category label → PS category |
| `bxmp_collection_state` | id_collection, status, date_upd | last status materialised by the cron (transition detection) |

**For the CSV importer (bxmpcsv)**: `require_once _PS_MODULE_DIR_ . 'bxmpcollections/vendor/autoload.php';`
`(new \BrainX\Collections\Repository\CategoryMapRepository())->resolve($label, $idVendor): ?int` — vendor mapping first,
then global; labels are compared case/accent/punctuation-insensitively ("Tazze & Mug" = "tazze mug").

## Demo data (`tools/DemoCollections.php`, windows relative to the seeding date)

Live: Christmas (−1 d → +60 d, Home Accessories + 3 products), Breakfast and Aperitivo (no window). Hidden: Easter
(+140 d, scheduled), Carnival (+95 d, scheduled), Halloween (−33 d → −2 d, expired), All Saints' Day (−8 d → −1 d, expired).
