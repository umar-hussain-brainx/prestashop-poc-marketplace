# BrainX Marketplace — Internal JSON API

Served by the core module `bxmarketplace` (`controllers/front/api.php` → `BrainX\Marketplace\Infrastructure\Api\Router`).
Machine-readable spec: [`openapi.yaml`](openapi.yaml) (OpenAPI 3.0).

## Base URL & routing

```
http://localhost:8080/index.php?fc=module&module=bxmarketplace&controller=api&resource=<path>
```

`resource` carries the REST path (`vendors`, `offers/12`, `products/20/offers`, `cart/optimize`, ...). Other query
parameters are passed as filters. Request/response bodies are JSON (`Content-Type: application/json`). Clients that
cannot send `PUT`/`PATCH` may `POST` with `X-HTTP-Method-Override: PATCH`.

## Authentication & scopes

Every request needs a token from `bxmp_api_token`:

```
Authorization: Bearer <64 hex chars>          (fallback header: X-Bxmp-Token: <token>)
```

| Token | `id_vendor` | Scope |
|---|---|---|
| Admin | `NULL` | all vendors, all offers, all vendor orders |
| Vendor | vendor id | only its own vendor record, offers (read/write) and vendor orders; anything else → `403 forbidden` |

Tokens are issued/revoked in **Modules → BrainX Marketplace (core) → Configure** (or `BxmpApiToken::issue()` /
`ApiTokenRepository::issue()`); the demo seeder prints one admin token and one token per demo vendor. Revoked tokens
and tokens of inactive vendors get `401`. Write calls, auth failures and server errors are logged in `bxmp_log`
(channel `api`).

## Errors

```json
{ "error": { "code": "validation_error", "message": "price is required", "details": { } } }
```

| HTTP | code | when |
|---|---|---|
| 400 | `bad_request` | body is not a JSON object |
| 401 | `unauthorized` | missing / invalid / revoked token |
| 403 | `forbidden` | vendor token touching another vendor's data |
| 404 | `not_found` | unknown resource or id |
| 405 | `method_not_allowed` | known resource, wrong method |
| 422 | `validation_error` / `unavailable_product` | invalid input; basket cannot be supplied (details.lines) |
| 500 | `internal_error` | unexpected (message only in dev mode) |

## Resources

All money is **EUR, tax excluded**. List endpoints accept `limit` (1-1000, default 100) and `offset` and return
`meta.count` / `meta.total`.

### `GET me`
Token introspection → `{ "data": { "id_api_token", "scope": "admin|vendor", "id_vendor", "label" } }`.

### `GET vendors` · `GET vendors/{id}`
Vendor list (vendor token: only itself). The detail includes `shipping_rules`.

```bash
curl -H "Authorization: Bearer $TOKEN" "$BASE&resource=vendors"
```
```json
{ "data": [ { "id_vendor": 1, "name": "Vendor A", "code": "vendor-a", "email": "vendor-a@marketplace.test",
  "company": "Vendor A S.r.l.", "vat_number": "IT01234560011", "active": true,
  "commission_rate": 10.0, "commission_fixed": 0.0, "date_add": "...", "date_upd": "..." } ], "meta": { "count": 3 } }
```

### `GET offers` · `GET offers/{id}`
Filters: `id_vendor`, `id_product`, `id_product_attribute`, `active` (0/1), `ean13`. Vendor tokens are forced to
their own `id_vendor` (asking for another one → 403).

### `POST offers` (upsert)
Creates or updates the offer identified by `(id_vendor, id_product, id_product_attribute)`. Body = one offer, or
`{"offers": [ ... ]}` for a batch (max 500; returns `207` with per-item `errors` if some fail).

| field | type | notes |
|---|---|---|
| `id_vendor` | int | required for admin tokens; implied (and enforced) for vendor tokens |
| `id_product` | int | master product (native PS product) — must exist |
| `id_product_attribute` | int | **required when the product has combinations** (no variant substitution); default 0 |
| `price` | number | required on create, ≥ 0, tax excl. |
| `discount_price`, `discount_from`, `discount_to` | number / `Y-m-d H:i:s` | optional discount window (open bounds allowed) |
| `quantity` | int | vendor stock ≥ 0 |
| `vendor_sku`, `ean13`, `condition` (`new`/`used`/`refurbished`), `active` | | `ean13` defaults to the master product/combination EAN |

```bash
curl -X POST -H "Authorization: Bearer $VENDOR_A_TOKEN" "$BASE&resource=offers" \
     -d '{"id_product":7,"price":9.99,"quantity":12,"vendor_sku":"A-7-0"}'
```
Dispatches hook `actionBxmpOfferUpdated` (`source: api`).

### `PUT|PATCH offers/{id}`
Partial update (price, stock, discount, active...). `id_vendor`, `id_product`, `id_product_attribute` are immutable.

### `GET products/{id}/offers` — price comparison
Ranks the offers of a master product (grouped per combination) by **landed total** = effective price × quantity +
the vendor's shipping for that destination, considering discount windows, availability and delivery eligibility.

Query: `id_product_attribute`, `quantity` (default 1), destination `country_iso` (or `id_country`) + `postcode`
(omit the destination to rank on price only).

```bash
curl -H "Authorization: Bearer $TOKEN" "$BASE&resource=products/6/offers&country_iso=IT&postcode=20121"
```
```json
{ "data": [ { "id_product": 6, "id_product_attribute": 0, "best_offer_id": 54, "offers": [
  { "id_offer": 54, "id_vendor": 3, "vendor_name": "Vendor C", "price": 10.59, "effective_price": 10.59,
    "on_discount": false, "quantity": 21, "available": true, "deliverable": true, "shipping_cost": 6.9,
    "landed_total": 17.49, "rank": 1, "best": true, "ineligible_reason": null }, ... ] } ],
  "meta": { "quantity": 1, "destination": { "id_country": 10, "postcode": "20121", "country_iso": "IT" } } }
```
`ineligible_reason`: `inactive`, `out_of_stock`, `insufficient_stock`, `no_delivery_to_destination` (unranked).

### `POST cart/optimize` — Smart Cart Optimizer (stateless)
Computes the allocation with the **lowest final amount** (items + per-vendor shipping with free/reduced
thresholds), splitting quantities across vendors under stock limits, honouring destination/postcode exclusions and
matching only on exact `(id_product, id_product_attribute)`. Nothing is stored (cart persistence is `bxmpcheckout`'s
job via `CartAllocationRepository`).

Body:
```json
{
  "lines": [ { "id_product": 20, "id_product_attribute": 0, "quantity": 1 },
             { "id_product": 21, "quantity": 1 } ],
  "destination": { "country_iso": "IT", "postcode": "20121" },
  "allow_partial": false
}
```
Offers and shipping rules are read from the catalog. For what-if simulations the caller may instead pass its own
data (`meta.offers_source = "request"`, nothing read from the DB):
```json
{ "lines": [...],
  "offers": [ { "id_offer": 1, "id_vendor": 1, "id_product": 1, "id_product_attribute": 0, "price": 5, "quantity": 10 } ],
  "shipping_policies": [ { "id_vendor": 1, "name": "Vendor A",
      "rules": [ { "id_country": 0, "zipcode_pattern": null, "excluded": 0, "base_cost": 5, "free_threshold": 30,
                   "reduced_threshold": null, "reduced_cost": null, "priority": 0 } ] } ] }
```

Response for the client reference scenario (seeded data):
```json
{ "data": {
    "total": 35.0, "items_total": 30.0, "shipping_total": 5.0, "vendor_count": 2, "complete": true,
    "vendors": [
      { "id_vendor": 1, "vendor_name": "Vendor A", "subtotal": 5.0, "shipping_cost": 5.0, "shipping_tier": "base", "total": 10.0,
        "lines": [ { "id_product": 20, "id_product_attribute": 0, "id_offer": 1, "vendor_sku": "VENDOR-A-P1", "quantity": 1, "unit_price": 5.0, "line_total": 5.0 } ] },
      { "id_vendor": 2, "vendor_name": "Vendor B", "subtotal": 25.0, "shipping_cost": 0.0, "shipping_tier": "free", "total": 25.0,
        "lines": [ { "id_product": 21, "id_product_attribute": 0, "id_offer": 4, "vendor_sku": "VENDOR-B-P2", "quantity": 1, "unit_price": 25.0, "line_total": 25.0 } ] } ],
    "strategy": { "mode": "exact", "proven_optimal": true, "nodes_explored": 3, "duration_ms": 1.6 },
    "alternatives": [
      { "label": "Cheapest offer for each item (ignoring shipping)", "total": 35.0, "feasible": true, "vendors": [1, 2] },
      { "label": "Everything from Vendor A", "total": 45.0, "feasible": true, "vendors": [1] },
      { "label": "Everything from Vendor B", "total": 45.0, "feasible": true, "vendors": [2] },
      { "label": "Everything from Vendor C", "total": 67.0, "feasible": true, "vendors": [3] },
      { "label": "Optimal allocation (selected)", "total": 35.0, "feasible": true, "vendors": [1, 2] } ],
    "explanation": [ "Basket: 2 line(s), 2 unit(s); 6 eligible offer(s) from 3 vendor(s) delivering to country #10 20121.",
      "Exact branch-and-bound over every vendor/quantity split (3 node(s) explored, seeded by 4 heuristic start(s)): optimum proven.",
      "Selected: €35.00 = items €30.00 + shipping €5.00 across 2 vendor(s) — Vendor A: ...; Vendor B: ...",
      "Saves €10.00 vs. the best single-vendor order (Everything from Vendor A: €45.00)." ],
    "excluded_offers": [], "unavailable": [] },
  "meta": { "offers_source": "catalog", "destination": { "id_country": 10, "postcode": "20121", "country_iso": "IT" } } }
```
`shipping_tier`: `base` | `reduced` | `free`. `strategy.mode`: `exact` (branch-and-bound, optimum proven) or
`heuristic` (large baskets). If a line cannot be supplied → `422 unavailable_product` with
`details.lines[] = {id_product, id_product_attribute, requested, available}`; with `"allow_partial": true` the
available quantity is allocated and the shortage reported in `unavailable` (`complete: false`).
Dispatches hook `actionBxmpCartOptimized` when offers come from the catalog.

### `GET vendor-orders` · `GET vendor-orders/{id}`
Vendor sub-orders (`bxmp_vendor_order`, created by `bxmpcheckout` on order validation). Filters: `id_vendor`,
`id_order`, `status` (`pending|accepted|shipped|delivered|cancelled`), `date_from`, `date_to` (`YYYY-MM-DD`),
`with_lines=1`. Vendor tokens only see their own. The detail always includes `lines`.

## Quick test

```bash
# tokens are printed by: docker compose exec -T -u www-data prestashop php modules/bxmarketplace/tools/seed_demo.php
BASE="http://localhost:8080/index.php?fc=module&module=bxmarketplace&controller=api"
curl -s -H "Authorization: Bearer $TOKEN" "$BASE&resource=vendors"
curl -s -X POST -H "Authorization: Bearer $TOKEN" "$BASE&resource=cart/optimize" \
     -d '{"lines":[{"id_product":20,"quantity":1},{"id_product":21,"quantity":1}],"destination":{"country_iso":"IT","postcode":"20121"}}'
```
(`20`/`21` are the ids the seeder gave "Product 1"/"Product 2" on the reference install; look them up by EAN
8000000000011 / 8000000000028 otherwise.)
