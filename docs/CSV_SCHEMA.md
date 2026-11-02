# Vendor CSV schema — BrainX Marketplace (module `bxmpcsv`)

One unified file format for every vendor, whatever their ERP. Templates: `modules/bxmpcsv/samples/offers-template.csv`
and `order-status-template.csv` (also downloadable in **Marketplace › Imports** → *CSV template*).

## File format

| Aspect | Rule |
|---|---|
| Encoding | UTF-8 (BOM accepted and stripped). Rows that are not valid UTF-8 (Excel "ANSI") are converted from Windows-1252. UTF-16 is rejected with a clear message. |
| Delimiter | Auto-detected among `;` `,` TAB `|` (header line, confirmed on the next lines). Can be forced per job (`delimiter` option). |
| Quoting | RFC 4180: `"..."` fields may contain the delimiter, new lines and doubled quotes `""`. |
| Header | Required, first line. Case/accents/spaces/dashes are ignored (`Price (tax excl)` = `price`, `Quantità` = `quantita`); aliases below. Unknown columns are ignored (reported in the job log). |
| Empty lines | Skipped. Row numbers in reports are spreadsheet rows (header = row 1). |
| Decimals | `12.5`, `12,5`, `1.234,56`, `1,234.56`, `1 234,56`, `1'234.50`, `€ 12,50`, `12.50 EUR`. When both `.` and `,` appear the right-most is the decimal separator. A single separator followed by exactly 3 digits (`1.000`) is ambiguous: in `;`/TAB/`|` files it is read as a thousands separator (European convention, decimal `,`), in `,` files as a decimal point. |
| Dates | `YYYY-MM-DD`, `YYYY-MM-DD HH:MM[:SS]`, ISO 8601 with `T`/timezone, `DD/MM/YYYY[ HH:MM[:SS]]`, `DD.MM.YYYY`, `DD-MM-YYYY`. A date-only `discount_to` means end of that day (23:59:59). Impossible dates (31/02) are errors. |
| Size | Max 100 MB per file. Any number of rows (processed in chunks of `BXMPCSV_CHUNK_SIZE`, default 500). |

## Offer / catalog columns (`type = offers`)

| Column | Aliases | Required | Validation / meaning |
|---|---|---|---|
| `vendor_sku` | sku, vendor_reference, seller_sku, codice, item_sku | **yes** | ≤ 64 chars, no `< > ; = { }`. Unique within the file (later duplicates are rejected). Key of the vendor's offer. |
| `ean` | ean13, gtin, gtin13, upc, barcode | one of ean / reference / title | Digits (spaces, dashes and dots ignored). 13 digits, 12 (UPC-A, zero padded) or 14 with a leading 0. A wrong GS1 check digit is a **warning** (still used for matching). EAN-8 = warning, ignored. Excel scientific notation (`8,00E+12`) is an error. **Primary match key.** |
| `reference` | mpn, manufacturer_reference, ref, part_number | " | ≤ 64 chars. Second match key (together with `brand`). Stored as product reference / MPN (≤ 40) of created products. |
| `brand` | manufacturer, marca | no | ≤ 64 chars. Must agree with the master product's manufacturer (otherwise *conflict*). Missing manufacturers are created. |
| `title` | name, product_name, titolo, nome | for creation | Forbidden PS characters `< > ; = # { }` are replaced (warning); truncated to 128 (warning). Used for title-similarity matching and as the product name. |
| `description` | descrizione, long_description | no | HTML allowed; scripts, iframes, forms, `on*=` handlers and `javascript:` URLs are removed. Short description = first 400 plain-text chars. |
| `category` | category_path, categoria | no | Category id, or a path `Home > Kitchen > Mugs` / `Kitchen/Mugs` (leading Home optional). Missing levels are created under Home (`BXMPCSV_CREATE_CATEGORIES`), else default category. |
| `price` | price_tax_excl, net_price, prezzo, unit_price | **yes** | Decimal > 0, **tax excluded**. Offer price; also the default price of a newly created master product. |
| `discount_price` | sale_price, special_price, promo_price | no | Decimal > 0 and **< price**. |
| `discount_from` / `discount_to` | discount_start / discount_end, sale_from / sale_to | no | Dates (above); `to` ≥ `from`. Ignored (warning) without `discount_price`. Empty = open-ended. |
| `quantity` | qty, stock, inventory, quantita | **yes** | Whole number ≥ 0 (`1.000` = 1000 in `;` files). Negative values → 0 (warning). |
| `images` | image, image_urls, immagini | no | `http(s)` URLs separated by `|`, first = cover, max 10. Only used when a master product is **created**; downloads are best-effort (failures are warnings). |
| `condition` | — | no | `new` (default), `used`, `refurbished` (+ it/fr/de synonyms: nuovo, usato, ricondizionato…). |
| `variant_attributes` | variant, variants, attributes, combination | no | `Group:Value|Group:Value` (e.g. `Color:Red|Size:M`; `=` and `,` also accepted). Creates a combination when the product is created; groups/values are created when missing. |
| `parent_reference` | parent_sku, group_reference, variant_group | no | Variant group key: rows sharing it become combinations of **one** master product (whose reference is this key). |
| `active` | enabled, status | no | `1/0`, `yes/no`, `true/false`, `y/n`, `sì` … (default 1). |

### What happens to a valid row

1. **Rejected before?** If the vendor SKU was rejected in the matching-exception queue it is skipped.
2. **Already mapped?** If the vendor already has an offer with this `vendor_sku` (and the EAN is the same or empty), that offer is updated directly. This is also how manual exception resolutions are replayed by later feeds.
3. **Core `MatchingEngine`** (EAN exact → reference + brand → title similarity):
   * `matched` → offer upserted (`OfferRepository::upsert`) on the master product / combination;
   * `new` → master product created (PS product, EAN, reference/MPN, brand → manufacturer, name, description, category,
     images, price = offer price; combination for variant rows) then the offer. Configurable (`BXMPCSV_AUTO_CREATE`): when
     disabled the row becomes an exception `no_match_auto_create_disabled`;
   * `ambiguous` / `conflict` → `bxmp_match_exception` (pending, one per vendor SKU, refreshed by later imports) and skipped.
   * A title-only candidate list where **every** candidate carries a *different* EAN than the row does not block creation
     (`BXMPCSV_EAN_DECIDES_NEW`): different GTINs are different products.
   * Variant rows whose `parent_reference` product already exists become a new combination of it.
4. PS stock (`StockAvailable`) of touched products = sum of active vendor offers (`BXMPCSV_SYNC_STOCK`);
   `actionBxmpOfferUpdated` is dispatched with `source = csv`.

**Full sync** (job option `full_sync`, default for feeds = `BXMPCSV_FULL_SYNC`): after the job, the vendor's offers that
were not in the file are set to quantity 0. Offers whose SKU *is* in the file but could not be applied (invalid row,
exception, skipped) are kept. Nothing is zeroed if the file had no valid row.

## Order status columns (`type = order_status`)

| Column | Aliases | Required | Meaning |
|---|---|---|---|
| `vendor_order_reference` | reference, order_reference, id_vendor_order | **yes** | `bxmp_vendor_order.reference` (or the numeric id). Must belong to the importing vendor (vendor 0 = admin, any vendor). |
| `status` | state, order_status, stato | **yes** (column) | `pending`, `accepted`, `shipped`, `delivered`, `cancelled` (+ synonyms such as confirmed, sent, spedito, canceled). Empty = keep the status (tracking-only update). |
| `tracking_number` | tracking, tracking_code | no | ≤ 128 |
| `carrier_name` | carrier, corriere | no | ≤ 128 |

Allowed transitions: pending → accepted | shipped | cancelled; accepted → shipped | cancelled; shipped → delivered; the
same status is allowed (tracking update). A status change dispatches `actionBxmpVendorOrderStatusUpdate`
(`id_vendor_order`, `old_status`, `new_status`, `tracking_number`, `carrier_name`, `source`).

## Order export (CSV produced by the marketplace)

One line per vendor order line, `;` + UTF-8 BOM, amounts tax excluded with `.` decimals:
`vendor_order_reference, id_vendor_order, vendor, order_reference, id_order, order_date, status, line, vendor_sku, ean13,
product_name, id_product, id_product_attribute, quantity, unit_price_tax_excl, line_total_tax_excl, total_products,
total_shipping, total_paid, commission_amount, vendor_payout, customer_name, customer_email, delivery_company,
delivery_address1, delivery_address2, delivery_postcode, delivery_city, delivery_state, delivery_country, delivery_phone,
tracking_number, carrier_name`. The vendor can fill `status` / `tracking_number` / `carrier_name` and re-import the
same file as an order status CSV.

## Reports

Every job keeps its status, counters (`rows_total`, `rows_ok`, `rows_error`) and a log in `bxmp_import_job`. Detailed
per-outcome counters (updated, created, combinations, exceptions, skipped, invalid, failed, warnings, zeroed) and a
per-row CSV report (`line; vendor_sku; outcome; messages`) can be downloaded from the job page.
