# bxmpcsv — BrainX Marketplace CSV import / export & synchronisation

Scope module 2 (standardized CSV import/export & synchronization) and the exception-handling half of module 3
(master catalog matching — the engine itself is the core `MatchingEngine`). Depends on `bxmarketplace` (schema,
ObjectModels, `MatchingEngine`, `OfferRepository`, `Logger`); creates **no table of its own**. Uses `bxmp_import_job`,
`bxmp_feed`, `bxmp_match_exception`, `bxmp_offer`, `bxmp_vendor_order(_line)` and native PS products. The file format
is described in [`docs/CSV_SCHEMA.md`](../../docs/CSV_SCHEMA.md).

## Install / seed / test

```bash
docker compose exec -T -u www-data prestashop php bin/console prestashop:module install bxmpcsv
docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tools/seed_feeds.php     # demo feeds A/B/C (idempotent)
cd modules/bxmpcsv && composer test      # PHPUnit: number parser, CSV reader/writer, schema, validators, row mapper (+ core engine)
composer build                           # back to the committed no-dev autoloader before committing
# integration checks against the running shop
docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tests/Integration/order_flow_check.php
docker compose exec -T -u www-data prestashop php modules/bxmpcsv/tests/Integration/perf_check.php --rows=4000
```

## Asynchronous processing — worker & cron

Every upload / URL / server path / due feed creates a `bxmp_import_job` (`queued`). The worker takes one job at a time
(MySQL named lock, so parallel cron runs are safe), snapshots the file into `var/bxmp_csv/jobs/<id>.csv`, pre-scans it
(header mapping, row count, duplicate SKUs) and processes it in chunks of `BXMPCSV_CHUNK_SIZE` rows (default 500). Each
chunk runs in one DB transaction and ends with a checkpoint (counters, log, resume offset in `jobs/<id>.json`), so a
time-boxed run stops between chunks and the next run resumes the job (`running` jobs are resumed first).

```cron
# CLI (recommended) — every minute, as the web server user
* * * * * www-data php /var/www/html/modules/bxmpcsv/tools/worker.php --max-time=55 --quiet
# or webcron (token in module configuration, which also prints the full URL)
* * * * * curl -fsS "http://localhost:8080/index.php?fc=module&module=bxmpcsv&controller=cron&token=<BXMPCSV_CRON_TOKEN>" >/dev/null
```

`tools/worker.php [--max-time=S] [--job=ID] [--no-feeds] [--loop[=SLEEP]] [--quiet]` (exit 0 ok, 1 job failed, 3 busy).
The cron endpoint answers JSON (`feeds_enqueued`, processed jobs, stats), 403 on a bad token; optional `max_time`,
`job`, `feeds=0`. Both first enqueue the due feeds (`FeedScheduler`).

## Back office (Sell › Marketplace)

| Tab | What |
|---|---|
| **Imports** (`AdminBxmpImports`) | Upload a CSV (or URL / server path) for a vendor, type offers / order status, full-sync & auto-create options, "process now"; job list (status, rows, ok, errors); job page with stats, log, per-row report CSV, link to its exceptions; **Run worker now**; CSV templates. |
| **Feeds** (`AdminBxmpFeeds`) | CRUD of `bxmp_feed` (vendor, URL or path relative to `var/bxmp_feeds`, interval minutes, active), next run, last job status, **Run now**. |
| **Matching exceptions** (`AdminBxmpMatchExceptions`) | Pending / resolved / rejected queue; detail page shows the vendor row, the engine verdict and the candidate products/combinations (EAN, reference, brand, existing offers). Actions: **link** to a candidate (or any product / combination id), **create new master product**, **retry matching**, **reject** (later imports skip the SKU); bulk reject / retry. Link / create replay the stored row and write the offer immediately. |
| **Order export** (`AdminBxmpOrderExport`) | Download vendor orders CSV (vendor or all, date range, statuses) and upload an order status CSV; summary per vendor/status. |
| Module **Configure** | Settings below, cron line + webcron URL, regenerate token. |

Settings (`Configuration`): `BXMPCSV_AUTO_CREATE` (1), `BXMPCSV_FULL_SYNC` (0, used by feeds), `BXMPCSV_CHUNK_SIZE` (500),
`BXMPCSV_MAX_RUNTIME` (50 s per cron run), `BXMPCSV_IMPORT_IMAGES` (1), `BXMPCSV_ID_CATEGORY` (0 = Home),
`BXMPCSV_CREATE_CATEGORIES` (1), `BXMPCSV_TAX_RULES_GROUP` (0), `BXMPCSV_PRODUCT_ACTIVE` (1), `BXMPCSV_TITLE_THRESHOLD`
(0.6), `BXMPCSV_EAN_DECIDES_NEW` (1), `BXMPCSV_SYNC_STOCK` (1), `BXMPCSV_ALLOWED_PATHS` (extra server dirs), `BXMPCSV_CRON_TOKEN`.

## Public PHP API (stable — used by the vendor portal `bxmpvendor`)

```php
require_once _PS_MODULE_DIR_ . 'bxmpcsv/autoload.php';   // core + BrainX\Csv\

use BrainX\Csv\{ImportService, ImportException, OrderExportService, OrderStatusService, FeedScheduler};
use BrainX\Csv\Service\MatchExceptionService;

$imports = new ImportService();
$idJob = $imports->enqueueUpload(int $idVendor, string $tmpFile, string $type = 'offers', array $options = []);
//   $type: 'offers' | 'order_status'; options: original_name, full_sync (bool), auto_create (bool), delimiter
//   throws ImportException (unknown vendor/type, empty/too large file, missing required columns...) — show getMessage()
$idJob = $imports->enqueueUrl(int $idVendor, string $url, string $type = 'offers', array $options = []);
$idJob = $imports->enqueuePath(int $idVendor, string $path, string $type = 'offers', array $options = []); // inside var/bxmp_feeds
$job   = $imports->process(int $idJob, int $maxSeconds = 30);   // optional synchronous run of that job
$job   = $imports->getJob(int $idJob);    // row + vendor_name, stats{updated,created,combinations,exceptions,skipped,invalid,failed,warnings,zeroed}, processed, log
$list  = $imports->listJobs(?int $idVendor = null, int $limit = 50, int $offset = 0);
$path  = $imports->errorReport(int $idJob);          // per-row report CSV path or null
$csv   = ImportService::templateCsv('offers');       // or 'order_status'

$exports = new OrderExportService();
$csv  = $exports->exportCsv(int $idVendor, array $filters = [], string $delimiter = ';'); // filters: date_from, date_to (Y-m-d), status (string|array), id_order; idVendor 0 = all
$rows = $exports->rows(int $idVendor, array $filters = []);
$n    = $exports->exportToFile(int $idVendor, string $path, array $filters = []);
$exports->download(int $idVendor, array $filters = [], ?string $filename = null);         // sends headers + exits

(new OrderStatusService())->apply(int $idVendor, string $reference, ?string $status, ?string $tracking = null, ?string $carrier = null, string $source = 'csv');
//   → [id_vendor_order, old_status, new_status, changed]; throws InvalidArgumentException (unknown order / not this vendor / forbidden transition)

(new FeedScheduler())->enqueue(int $idFeed);     // enqueueDue(), dueFeeds(), nextRun($feedRow)
$ex = new MatchExceptionService();               // pending(?$idVendor), find($id), candidates($id), link($id, $idProduct, $idAttr), createProduct($id), retry($id), reject($id)
```

CLI order export: `php modules/bxmpcsv/tools/export_orders.php --vendor=1 [--from=2026-11-01] [--to=2026-11-30] [--status=pending,accepted] [--out=file.csv]`.

## Hooks

Dispatched: `actionBxmpOfferUpdated` (`source` = `csv` / `csv_full_sync`, per written offer) and
`actionBxmpVendorOrderStatusUpdate` (`id_vendor_order`, `old_status`, `new_status`, `tracking_number`, `carrier_name`, `source`).

## Samples & tools

`samples/offers-template.csv`, `samples/order-status-template.csv`, `samples/feeds/vendor-{a,b,c}.csv` (demo feeds — `;` +
decimal comma + BOM / `,` + dot / TAB — equal to the seeded offers), `samples/vendor-a-mixed-test.csv` (48 rows: matched by SKU,
EAN and reference, new products incl. images and variants, ambiguous, conflict, invalid and warning rows).
`tools/generate_perf_csv.php --rows=4000 --out=f.csv` generates synthetic files.

## Notes / limitations (POC)

- One offer per vendor and product/combination (core unique key): two vendor SKUs mapped to the same combination overwrite
  each other (the BO link action warns about it).
- Images are only imported when a master product is created; existing master product content is never overwritten by
  vendor files (the master catalog stays curated).
- Created products use tax rules group `BXMPCSV_TAX_RULES_GROUP` and a single language value for all languages.
- Files are kept in `var/bxmp_csv/jobs/` (not web-accessible); there is no automatic purge yet.
