/**
 * Vendor portal end-to-end checks (headless Chromium, Playwright library — no test runner needed).
 *
 *   cd modules/bxmpvendorportal/tests/e2e && NODE_PATH=$(npm root -g) node portal.e2e.js
 *
 * Env: BASE_URL (default http://localhost:8080), SHOTS (screenshot dir, default <repo>/docs/screenshots),
 *      DB_CMD (shell prefix to run SQL, default "docker compose exec -T db mariadb -uprestashop -pprestashop prestashop -N -e").
 * Prerequisites: core demo data (bxmarketplace/tools/seed_demo.php) and
 *                php modules/bxmpvendorportal/tools/seed_history.php --staff  (history + staff-a / manager-a users).
 * Every mutation made by the suite is reverted at the end (offer price, shipping rule, user status), except the
 * shipped HIST- demo order (re-run seed_history.php --reset to restore pristine history).
 */
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://localhost:8080';
const REPO = path.resolve(__dirname, '../../../..');
const SHOTS = process.env.SHOTS || path.join(REPO, 'docs/screenshots');
const DB_CMD = process.env.DB_CMD || 'docker compose exec -T db mariadb -uprestashop -pprestashop prestashop -N -e';
const PWD = 'Vendor123!';
const url = (c, q = '') => `${BASE}/module/bxmpvendorportal/${c}${q ? '?' + q : ''}`;

let passed = 0;
const failures = [];
function check(name, cond, extra = '') {
  if (cond) { passed++; console.log(`  ok   ${name}`); } else { failures.push(name); console.log(`  FAIL ${name} ${extra}`); }
}
function sql(q) {
  return execSync(`${DB_CMD} ${JSON.stringify(q)}`, { cwd: REPO, encoding: 'utf8' }).trim();
}

async function login(browser, email, password = PWD) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  await page.goto(`${BASE}/login?back=my-account`);
  await page.fill('#field-email', email);
  await page.fill('#field-password', password);
  await Promise.all([page.waitForNavigation(), page.click('#submit-login')]);
  return { context, page };
}
async function csrf(page) {
  return page.locator('.bxmpv[data-csrf]').first().getAttribute('data-csrf');
}
/** Raw POST sharing the browser session (forged / tampered requests). */
async function post(page, controller, form) {
  return page.context().request.post(url(controller), { form, maxRedirects: 0 });
}

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true });
  const browser = await chromium.launch();
  const idA = Number(sql("SELECT id_vendor FROM ps_bxmp_vendor WHERE code='vendor-a'"));
  const idB = Number(sql("SELECT id_vendor FROM ps_bxmp_vendor WHERE code='vendor-b'"));
  const offerA = Number(sql(`SELECT id_offer FROM ps_bxmp_offer WHERE id_vendor=${idA} AND vendor_sku='VENDOR-A-P1'`));
  const offerB = Number(sql(`SELECT id_offer FROM ps_bxmp_offer WHERE id_vendor=${idB} AND vendor_sku='VENDOR-B-P1'`));
  const priceA = sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerA}`);
  const priceB = sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerB}`);
  const orderB = Number(sql(`SELECT id_vendor_order FROM ps_bxmp_vendor_order WHERE id_vendor=${idB} ORDER BY id_vendor_order DESC LIMIT 1`));
  const ruleB = Number(sql(`SELECT id_shipping_rule FROM ps_bxmp_shipping_rule WHERE id_vendor=${idB} LIMIT 1`));
  if (!offerA || !offerB || !orderB) throw new Error('Demo data missing: run seed_demo.php and seed_history.php --staff first');

  // ------------------------------------------------------------------ anonymous & non-vendor
  console.log('Access control (anonymous / non-vendor)');
  {
    const ctx = await browser.newContext();
    const p = await ctx.newPage();
    await p.goto(url('dashboard'));
    check('anonymous visitor is redirected to the login page', /login|authentication/.test(p.url()), p.url());
    await ctx.close();
    const { context, page } = await login(browser, 'customer@marketplace.test', 'Customer123!');
    const r = await page.goto(url('dashboard'));
    check('non-vendor customer gets 403', r.status() === 403 && await page.locator('[data-testid=denied]').count() === 1, String(r.status()));
    await page.goto(`${BASE}/my-account`);
    check('no "Vendor portal" link in a non-vendor account', await page.locator('#bxmpv-vendor-portal-link').count() === 0);
    await context.close();
  }

  // ------------------------------------------------------------------ owner: every page
  console.log('Owner vendor-a: pages');
  const { context: ownerCtx, page } = await login(browser, 'vendor-a@marketplace.test');
  await page.goto(`${BASE}/my-account`);
  check('"Vendor portal" link in customer account', await page.locator('#bxmpv-vendor-portal-link').count() === 1);
  check('"Vendor portal" link in the header nav', await page.locator('#bxmpv-nav-link').count() === 1);
  for (const c of ['dashboard', 'offers', 'positioning', 'orders', 'profile', 'users', 'csv']) {
    const r = await page.goto(url(c));
    check(`owner opens ${c} (200)`, r.status() === 200 && await page.locator('.bxmpv-title').count() === 1, String(r.status()));
  }

  await page.goto(url('dashboard', 'period=90d'));
  check('dashboard shows 6 KPI tiles', await page.locator('[data-testid=kpis] .bxmpv-kpi').count() === 6);
  check('dashboard GMV is non-zero (history seeded)', !/€0\.00/.test(await page.locator('[data-kpi=gmv] .bxmpv-kpi__value').innerText()));
  check('trend chart rendered as inline SVG', await page.locator('#bxmpv-trend svg rect.bar').count() > 5);
  check('best-sellers table has rows', await page.locator('[data-testid=best-sellers] tbody tr').count() >= 3);
  await Promise.all([page.waitForNavigation(), page.selectOption('select[name=granularity]', 'month')]);
  check('granularity switch → monthly buckets', (await page.locator('#bxmpv-trend svg rect.bar').count()) <= 4);
  await page.goto(url('dashboard', 'period=90d&granularity=week'));
  await page.screenshot({ path: path.join(SHOTS, 'vendor-dashboard.png'), fullPage: true });

  // ------------------------------------------------------------------ offers: positioning + inline price edit
  console.log('Offers & positioning');
  await page.goto(url('offers', 'q=VENDOR-A-P1'));
  const row = page.locator(`tr[data-offer="${offerA}"]`);
  check('own offer found by SKU search', await row.count() === 1);
  check('competitive position shows buy box for Vendor A on Product 1', (await row.locator('[data-testid=position]').getAttribute('data-buybox')) === '1');
  await row.locator('[data-testid=edit-offer]').click();
  const form = page.locator(`[data-testid=offer-form-${offerA}]`);
  await form.locator('input[name=price]').fill('5.25');
  await form.locator('input[name=quantity]').fill('50');
  await Promise.all([page.waitForNavigation(), form.locator('button[type=submit]').click()]);
  check('price edit saved (UI)', (await page.locator(`tr[data-offer="${offerA}"] [data-testid=price]`).innerText()).trim() === '5.25');
  check('price edit saved (DB)', Number(sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerA}`)) === 5.25);
  check('success notification shown', await page.locator('.alert-success, #notifications .alert-success').count() > 0);
  // invalid discount → validation error, nothing saved
  await page.locator(`tr[data-offer="${offerA}"] [data-testid=edit-offer]`).click();
  await form.locator('input[name=discount_price]').fill('9');
  await Promise.all([page.waitForNavigation(), form.locator('button[type=submit]').click()]);
  check('discount ≥ price rejected', await page.locator('.alert-danger').count() > 0 && sql(`SELECT IFNULL(discount_price,'NULL') FROM ps_bxmp_offer WHERE id_offer=${offerA}`) === 'NULL');
  // restore client-scenario price (other modules rely on €5)
  await post(page, 'offers', { csrf_token: await csrf(page), action: 'update', id_offer: offerA, price: priceA, quantity: '50', active: '1' });
  check('price restored', Number(sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerA}`)) === Number(priceA));

  await page.goto(url('offers'));
  await page.screenshot({ path: path.join(SHOTS, 'vendor-offers.png'), fullPage: true });
  await page.goto(url('offers', 'mq=8000000000028'));
  check('add-offer catalog search by EAN finds Product 2 (already offered)', await page.locator('[data-testid=add-offer] tbody tr').count() >= 1);

  await page.goto(url('positioning'));
  check('positioning table lists own SKUs', await page.locator('[data-testid=positioning-table] tbody tr').count() >= 10);
  const p2 = page.locator('[data-testid=positioning-table] tr', { hasText: 'Product 2' });
  check('Product 2: Vendor A not cheapest, suggested 24.99', (await p2.locator('[data-testid=suggested]').innerText()).includes('24.99'));
  await page.screenshot({ path: path.join(SHOTS, 'vendor-positioning.png'), fullPage: true });

  // ------------------------------------------------------------------ orders: accept → ship with tracking
  console.log('Orders workflow');
  const pendingId = Number(sql(`SELECT id_vendor_order FROM ps_bxmp_vendor_order WHERE id_vendor=${idA} AND status='pending' ORDER BY date_add DESC LIMIT 1`));
  check('a pending order exists for vendor-a', pendingId > 0);
  await page.goto(url('orders', 'status=pending'));
  check('status filter shows only pending rows', await page.locator('[data-testid=orders-table] tbody tr[data-status]:not([data-status=pending])').count() === 0);
  await page.screenshot({ path: path.join(SHOTS, 'vendor-orders.png'), fullPage: true });
  await page.goto(url('orders', `id=${pendingId}`));
  check('order detail shows lines', await page.locator('[data-testid=order-lines] tbody tr').count() >= 1);
  await Promise.all([page.waitForNavigation(), page.click('[data-testid=accept]')]);
  check('order accepted', sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_vendor_order=${pendingId}`) === 'accepted');
  // ship without tracking → refused server side (bypass browser validation)
  let r = await post(page, 'orders', { csrf_token: await csrf(page), action: 'ship', id_vendor_order: pendingId, carrier_name: 'BRT', tracking_number: '' });
  check('ship without tracking refused', sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_vendor_order=${pendingId}`) === 'accepted');
  await page.goto(url('orders', `id=${pendingId}`));
  await page.fill('[data-testid=ship-form] input[name=carrier_name]', 'BRT');
  await page.fill('[data-testid=ship-form] input[name=tracking_number]', 'BRT-E2E-123456');
  await Promise.all([page.waitForNavigation(), page.click('[data-testid=ship]')]);
  check('order shipped with tracking (UI)', (await page.locator('[data-testid=order-status]').textContent()).trim() === 'shipped' && (await page.locator('[data-testid=tracking]').innerText()).includes('BRT-E2E-123456'));
  check('order shipped with tracking (DB)', sql(`SELECT CONCAT(status,'|',tracking_number,'|',carrier_name) FROM ps_bxmp_vendor_order WHERE id_vendor_order=${pendingId}`) === 'shipped|BRT-E2E-123456|BRT');
  r = await post(page, 'orders', { csrf_token: await csrf(page), action: 'cancel', id_vendor_order: pendingId });
  check('cannot cancel a shipped order', sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_vendor_order=${pendingId}`) === 'shipped');
  await page.goto(url('orders', `id=${pendingId}`));
  await page.screenshot({ path: path.join(SHOTS, 'vendor-order-detail.png'), fullPage: true });
  const csvResp = await page.context().request.get(url('orders', 'action=export&status=shipped'));
  const csvBody = await csvResp.text();
  check('orders CSV export (own rows only)', csvResp.status() === 200 && csvBody.includes('BRT-E2E-123456') && !csvBody.includes('HIST-VENDOR-B'));

  // ------------------------------------------------------------------ profile & shipping rules
  console.log('Commercial data & shipping rules');
  await page.goto(url('profile'));
  check('commission shown read-only (10 %)', /10\s%/.test(await page.locator('[data-testid=commission]').textContent()));
  await page.goto(url('profile', 'rule=new'));
  await page.selectOption('[data-testid=rule-form] select[name=id_country]', { label: 'Italy' });
  await page.fill('[data-testid=rule-form] input[name=zipcode_pattern]', '07, 08');
  await page.check('[data-testid=rule-form] input[name=excluded]');
  await page.fill('[data-testid=rule-form] input[name=priority]', '10');
  await Promise.all([page.waitForNavigation(), page.click('[data-testid=rule-form] button[type=submit]')]);
  const newRule = Number(sql(`SELECT id_shipping_rule FROM ps_bxmp_shipping_rule WHERE id_vendor=${idA} AND zipcode_pattern='07,08' AND excluded=1`));
  check('exclusion rule created (normalised pattern 07,08)', newRule > 0);
  await page.goto(url('profile', 'sim_country=10&sim_zip=07100&sim_subtotal=20'));
  check('simulator: no delivery to excluded zone', (await page.locator('[data-testid=sim-result]').innerText()).includes('No delivery'));
  await page.goto(url('profile', 'sim_country=10&sim_zip=20121&sim_subtotal=20'));
  check('simulator: €5 below free threshold', (await page.locator('[data-testid=sim-result]').innerText()).includes('5.00'));
  await page.goto(url('profile', 'rule=new'));
  await page.fill('[data-testid=rule-form] input[name=zipcode_pattern]', '20;21');
  await Promise.all([page.waitForNavigation(), page.click('[data-testid=rule-form] button[type=submit]')]);
  check('invalid postcode pattern rejected', await page.locator('.alert-danger').count() > 0);
  await page.goto(url('profile'));
  await page.screenshot({ path: path.join(SHOTS, 'vendor-shipping-rules.png'), fullPage: true });
  await post(page, 'profile', { csrf_token: await csrf(page), action: 'delete_rule', id_shipping_rule: newRule });
  check('rule deleted (cleanup)', sql(`SELECT COUNT(*) FROM ps_bxmp_shipping_rule WHERE id_shipping_rule=${newRule}`) === '0');

  // ------------------------------------------------------------------ CSRF + cross-vendor tampering
  console.log('CSRF & cross-vendor isolation');
  r = await post(page, 'offers', { action: 'update', id_offer: offerA, price: '1.00', quantity: '1', active: '1' });
  check('POST without CSRF token → 403', r.status() === 403 && Number(sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerA}`)) === Number(priceA), String(r.status()));
  r = await post(page, 'offers', { csrf_token: 'x'.repeat(64), action: 'update', id_offer: offerA, price: '1.00', quantity: '1', active: '1' });
  check('POST with wrong CSRF token → 403', r.status() === 403);
  const token = await (async () => { await page.goto(url('offers')); return csrf(page); })();
  r = await post(page, 'offers', { csrf_token: token, action: 'update', id_offer: offerB, price: '0.50', quantity: '999', active: '1' });
  check("tampered id: update another vendor's offer → 404, unchanged", r.status() === 404 && sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerB}`) === priceB, String(r.status()));
  r = await post(page, 'positioning', { csrf_token: token, action: 'apply', id_offer: offerB, price: '0.50' });
  check("tampered id: apply price on another vendor's offer → 404", r.status() === 404 && sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerB}`) === priceB);
  r = await page.goto(url('orders', `id=${orderB}`));
  check("tampered id: open another vendor's order → 404", r.status() === 404);
  const statusB = sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_vendor_order=${orderB}`);
  r = await post(page, 'orders', { csrf_token: token, action: 'cancel', id_vendor_order: orderB });
  check("tampered id: cancel another vendor's order → 404, unchanged", r.status() === 404 && sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_vendor_order=${orderB}`) === statusB);
  r = await page.goto(url('profile', `rule=${ruleB}`));
  check("tampered id: edit another vendor's shipping rule → 404", r.status() === 404);
  r = await post(page, 'profile', { csrf_token: token, action: 'delete_rule', id_shipping_rule: ruleB });
  check("tampered id: delete another vendor's shipping rule → 404, kept", r.status() === 404 && sql(`SELECT COUNT(*) FROM ps_bxmp_shipping_rule WHERE id_shipping_rule=${ruleB}`) === '1');
  r = await post(page, 'offers', { csrf_token: token, action: 'update', id_offer: offerA, id_vendor: idB, price: priceA, quantity: '50', active: '1' });
  check('posted id_vendor is ignored (offer stays with vendor-a)', Number(sql(`SELECT id_vendor FROM ps_bxmp_offer WHERE id_offer=${offerA}`)) === idA);
  const bOwner = Number(sql(`SELECT id_vendor_user FROM ps_bxmp_vendor_user WHERE id_vendor=${idB} LIMIT 1`));
  r = await post(page, 'users', { csrf_token: token, action: 'update', id_vendor_user: bOwner, role: 'staff', active: '0' });
  check("tampered id: deactivate another vendor's user → 404", r.status() === 404 && sql(`SELECT active FROM ps_bxmp_vendor_user WHERE id_vendor_user=${bOwner}`) === '1');
  await page.goto(url('orders'));
  check('order list never shows another vendor’s references', !(await page.content()).includes('HIST-VENDOR-B'));

  // ------------------------------------------------------------------ users page (owner): deactivate / reactivate staff
  console.log('Users');
  await page.goto(url('users'));
  check('users page lists staff-a', await page.locator('tr[data-user="staff-a@marketplace.test"]').count() === 1);
  await page.screenshot({ path: path.join(SHOTS, 'vendor-users.png'), fullPage: true });
  await Promise.all([page.waitForNavigation(), page.locator('tr[data-user="staff-a@marketplace.test"] [data-testid=toggle-user]').click()]);
  check('staff-a deactivated', sql("SELECT vu.active FROM ps_bxmp_vendor_user vu JOIN ps_customer c USING(id_customer) WHERE c.email='staff-a@marketplace.test'") === '0');
  {
    const { context, page: sp } = await login(browser, 'staff-a@marketplace.test');
    const rr = await sp.goto(url('orders'));
    check('deactivated user loses access (403)', rr.status() === 403);
    await context.close();
  }
  await page.goto(url('users'));
  await Promise.all([page.waitForNavigation(), page.locator('tr[data-user="staff-a@marketplace.test"] [data-testid=toggle-user]').click()]);
  check('staff-a reactivated', sql("SELECT vu.active FROM ps_bxmp_vendor_user vu JOIN ps_customer c USING(id_customer) WHERE c.email='staff-a@marketplace.test'") === '1');
  const me = Number(sql("SELECT vu.id_vendor_user FROM ps_bxmp_vendor_user vu JOIN ps_customer c USING(id_customer) WHERE c.email='vendor-a@marketplace.test'"));
  await post(page, 'users', { csrf_token: token, action: 'update', id_vendor_user: me, role: 'owner', active: '0' });
  check('owner cannot deactivate himself', sql(`SELECT active FROM ps_bxmp_vendor_user WHERE id_vendor_user=${me}`) === '1');
  await ownerCtx.close();

  // ------------------------------------------------------------------ staff role restrictions
  console.log('Staff role');
  {
    const { context, page: sp } = await login(browser, 'staff-a@marketplace.test');
    let rr = await sp.goto(url('dashboard'));
    check('staff dashboard: operational view, no financial KPIs', rr.status() === 200 && await sp.locator('[data-testid=kpis]').count() === 0 && await sp.locator('[data-testid=open-orders]').count() === 1);
    rr = await sp.goto(url('offers'));
    check('staff sees offers read-only (no edit buttons)', rr.status() === 200 && await sp.locator('[data-testid=readonly-note]').count() === 1 && await sp.locator('[data-testid=edit-offer]').count() === 0);
    for (const c of ['positioning', 'profile', 'users']) {
      rr = await sp.goto(url(c));
      check(`staff denied on ${c} (403)`, rr.status() === 403);
    }
    check('staff nav hides forbidden pages', await sp.locator('.bxmpv-nav__link[data-page=users]').count() === 0);
    rr = await sp.goto(url('orders'));
    check('staff can open orders', rr.status() === 200);
    const st = await csrf(sp);
    rr = await post(sp, 'offers', { csrf_token: st, action: 'update', id_offer: offerA, price: '1.11', quantity: '1', active: '1' });
    check('staff forged offer update → 403, unchanged', rr.status() === 403 && Number(sql(`SELECT price FROM ps_bxmp_offer WHERE id_offer=${offerA}`)) === Number(priceA), String(rr.status()));
    rr = await post(sp, 'offers', { csrf_token: st, action: 'bulk_stock', bulk: 'VENDOR-A-P1;1' });
    check('staff forged bulk stock → 403', rr.status() === 403 && sql(`SELECT quantity FROM ps_bxmp_offer WHERE id_offer=${offerA}`) === '50');
    rr = await post(sp, 'profile', { csrf_token: st, action: 'save_profile', company: 'Hacked', email: 'x@y.z' });
    check('staff forged profile update → 403', rr.status() === 403 && sql(`SELECT company FROM ps_bxmp_vendor WHERE id_vendor=${idA}`) !== 'Hacked');
    rr = await post(sp, 'users', { csrf_token: st, action: 'add', email: 'customer@marketplace.test', role: 'owner' });
    check('staff forged user add → 403', rr.status() === 403 && sql("SELECT COUNT(*) FROM ps_bxmp_vendor_user vu JOIN ps_customer c USING(id_customer) WHERE c.email='customer@marketplace.test'") === '0');
    // staff CAN fulfil orders
    const acc = Number(sql(`SELECT id_vendor_order FROM ps_bxmp_vendor_order WHERE id_vendor=${idA} AND status='shipped' ORDER BY date_add DESC LIMIT 1`));
    await sp.goto(url('orders', `id=${acc}`));
    await Promise.all([sp.waitForNavigation(), sp.click('[data-testid=deliver]')]);
    check('staff marks a shipped order delivered', sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_vendor_order=${acc}`) === 'delivered');
    await context.close();
  }

  // ------------------------------------------------------------------ manager role
  console.log('Manager role');
  {
    const { context, page: mp } = await login(browser, 'manager-a@marketplace.test');
    let rr = await mp.goto(url('positioning'));
    check('manager opens positioning', rr.status() === 200);
    rr = await mp.goto(url('profile'));
    check('manager denied on commercial data (403)', rr.status() === 403);
    await context.close();
  }

  // ------------------------------------------------------------------ mobile layout
  {
    const { context, page: mp } = await login(browser, 'vendor-a@marketplace.test');
    await mp.setViewportSize({ width: 390, height: 844 });
    await mp.goto(url('dashboard'));
    const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('dashboard has no horizontal overflow at 390px', overflow <= 1, String(overflow));
    await mp.screenshot({ path: path.join(SHOTS, 'vendor-dashboard-mobile.png'), fullPage: false });
    await context.close();
  }

  await browser.close();
  console.log(`\n${passed} passed, ${failures.length} failed`);
  if (failures.length) { console.log('Failures:\n - ' + failures.join('\n - ')); process.exit(1); }
})().catch((e) => { console.error(e); process.exit(2); });
