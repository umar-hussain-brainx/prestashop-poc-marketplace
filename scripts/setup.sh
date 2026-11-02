#!/usr/bin/env bash
# Installs all marketplace modules into the running PrestaShop container and loads demo data.
# Usage: docker compose up -d && ./scripts/setup.sh
set -euo pipefail
cd "$(dirname "$0")/.."
PS="docker compose exec -T -u www-data prestashop"

echo "* Waiting for PrestaShop to finish its first-boot install..."
until curl -fsS -o /dev/null http://localhost:8080/; do sleep 5; done

for m in bxmarketplace bxmpcheckout bxmpcsv bxmpvendorportal bxmpadmin bxmpcollections; do
  if [ -f "modules/$m/$m.php" ]; then
    echo "* Installing $m"
    $PS php bin/console prestashop:module install "$m" || $PS php bin/console prestashop:module enable "$m"
  fi
done

echo "* Seeding demo data (vendors, offers, client reference scenario)"
$PS php modules/bxmarketplace/tools/seed_demo.php
[ -f modules/bxmpvendorportal/tools/seed_history.php ] && $PS php modules/bxmpvendorportal/tools/seed_history.php || true
[ -f modules/bxmpcollections/tools/cron.php ] && $PS php modules/bxmpcollections/tools/cron.php || true

$PS php bin/console cache:clear >/dev/null 2>&1 || true
echo "* Done. Storefront http://localhost:8080  |  Back office http://localhost:8080/admin-dev (admin@marketplace.test / Admin123!)"
