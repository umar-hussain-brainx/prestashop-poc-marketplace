# BrainX Multi-Vendor Marketplace — PrestaShop 9 POC

Proof of concept demonstrating that the client's 10-module multi-vendor B2B/B2C marketplace can be built on
**stable PrestaShop 9.x (9.0.3)** using only proprietary custom modules — no core modifications, no overrides,
no third-party multi-vendor modules.

- Architecture & data contracts: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Final POC report (mapped to the scope document): [`docs/POC_REPORT.md`](docs/POC_REPORT.md)

## Quick start

```bash
docker compose up -d          # first boot auto-installs PrestaShop 9.0.3 (~1-2 min)
./scripts/setup.sh            # installs the marketplace modules + demo data (vendors, offers, client scenario)
```

- Storefront: http://localhost:8080
- Back office: http://localhost:8080/admin-dev — `admin@marketplace.test` / `Admin123!`

## Custom modules

| Module | Purpose |
|---|---|
| `modules/bxmarketplace` | Core: schema, domain engines (matching, price comparison, cart optimizer, commissions), internal API, vendor admin |
| `modules/bxmpcsv` | Standardized CSV import/export, async job queue, scheduled feed sync, matching exceptions |
| `modules/bxmpcheckout` | Smart cart integration, vendor shipping carrier, order splitting, payment allocation |
| `modules/bxmpvendor` | Vendor portal: offers, inventory, orders, shipments, analytics |
| `modules/bxmpadmin` | Central analytics, settlements, exportable reports |
| `modules/bxmpcollections` | Thematic collections with scheduled activation |
