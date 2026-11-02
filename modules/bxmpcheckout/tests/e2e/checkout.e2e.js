#!/usr/bin/env node
/*
 * End-to-end check of the multi-vendor checkout on the running demo shop (headless Chromium, Playwright).
 *
 *   node modules/bxmpcheckout/tests/e2e/checkout.e2e.js            (from the repository root, stack running)
 *   BASE_URL=http://localhost:8080 HEADED=1 node ...               (options)
 *
 * Scenario 1 (client scenario): Product 1 (id 20) + Product 2 (id 21), qty 1 each, Milano 20121
 *   → cart items €30, shipping €5, total €35; order split A (P1 €5 + €5 shipping, 10 %) / B (P2 €25, free shipping, 12.5 %).
 * Scenario 2 (quantity split): Hummingbird t-shirt combination 2, qty 5 (seed: B has 3 @ €22.23, A has 14 @ €26.77)
 *   → 3 from B + 2 from A (= €120.23 with seed prices; expectations are read from the live offers), free shipping,
 *   blended cart unit price; order split into 2 vendor orders.
 * Scenario 3 (blocking): vendor A's stock drops to 1 while the 5 units sit in the cart → message + checkout disabled +
 *   /order redirects to the cart, never a substitution; stock restored → unblocked.
 * BO: vendor split panel on the order page + per-vendor status/tracking update.
 *
 * Vendor offer stock touched by the orders is restored at the end so the script can be re-run.
 * Screenshots: docs/screenshots/checkout-*.png
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '../../../..');
const BASE = (process.env.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const SHOTS = path.join(ROOT, 'docs/screenshots');
const CUSTOMER = { email: 'customer@marketplace.test', password: 'Customer123!' };
const ADMIN = { email: 'admin@marketplace.test', password: 'Admin123!' };
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
const results = [];
function check(label, cond, detail = '') {
  results.push(`${cond ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`);
  console.log(results[results.length - 1]);
  if (!cond) failures++;
}
const near = (a, b) => Math.abs(a - b) < 0.005;
const money = (s) => {
  const t = String(s || '').replace(/[^0-9.,-]/g, '');
  if (!t) return NaN;
  return parseFloat(t.includes(',') && !t.includes('.') ? t.replace(',', '.') : t.replace(/,/g, ''));
};
function sql(query) {
  const out = execFileSync('docker', ['compose', 'exec', '-T', 'db', 'mariadb', '-uprestashop', '-pprestashop', 'prestashop', '-N', '-B', '-e', query], { cwd: ROOT, encoding: 'utf8' });
  return out.trim() ? out.trim().split('\n').map((l) => l.split('\t')) : [];
}

async function emptyCart(page) {
  await page.goto(`${BASE}/index.php?controller=cart&action=show`);
  for (let i = 0; i < 10; i++) {
    const rm = page.locator('a.remove-from-cart').first();
    if (!(await rm.count())) break;
    await Promise.all([page.waitForResponse((r) => r.url().includes('controller=cart') || r.url().includes('/cart'), { timeout: 15000 }).catch(() => {}), rm.click()]);
    await page.waitForTimeout(800);
    await page.reload();
  }
}

async function addToCart(page, url, qty, shotName) {
  await page.goto(url);
  await page.waitForSelector('#bxmpco-offers');
  if (shotName) {
    await page.locator('#bxmpco-offers').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(SHOTS, shotName), fullPage: false });
  }
  if (qty !== 1) {
    await page.fill('#quantity_wanted', String(qty));
  }
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && /cart/.test(r.url()), { timeout: 20000 }),
    page.click('button.add-to-cart'),
  ]);
  await page.waitForTimeout(500);
}

async function cartTotals(page) {
  await page.goto(`${BASE}/index.php?controller=cart&action=show`);
  await page.waitForSelector('.cart-detailed-totals, .cart-summary');
  const txt = async (sel) => ((await page.locator(sel).count()) ? (await page.locator(sel).first().innerText()) : '');
  return {
    products: money(await txt('#cart-subtotal-products .value')),
    shipping: await txt('#cart-subtotal-shipping .value'),
    total: money(await txt('.cart-detailed-totals .cart-total .value, .cart-summary-totals .cart-total .value')),
    allocItems: money(await txt('#bxmpco-allocation [data-bxmpco="items"]')),
    allocShipping: await txt('#bxmpco-allocation [data-bxmpco="shipping"]'),
    allocTotal: money(await txt('#bxmpco-allocation [data-bxmpco="total"]')),
  };
}

async function checkout(page, shotPrefix) {
  await page.goto(`${BASE}/index.php?controller=order`);
  await page.waitForLoadState('networkidle');
  // addresses step (customer has a Milano address) — PS may or may not open on it
  for (let i = 0; i < 2 && !(await page.locator('#checkout-delivery-step.-current').count()); i++) {
    const confirmAddr = page.locator('#checkout-addresses-step button[name="confirm-addresses"]');
    if (await confirmAddr.isVisible().catch(() => false)) {
      await Promise.all([page.waitForLoadState('load'), confirmAddr.click()]);
      await page.waitForLoadState('networkidle');
    }
  }
  await page.waitForSelector('#checkout-delivery-step.-current .delivery-options', { timeout: 20000 });
  const carrierId = sql("SELECT id_carrier FROM ps_carrier WHERE external_module_name='bxmpcheckout' AND deleted=0 ORDER BY id_carrier DESC LIMIT 1")[0][0];
  const radios = await page.locator('input[name^="delivery_option"]').count();
  check(`${shotPrefix}: only the marketplace carrier is offered`, radios === 1, `${radios} option(s)`);
  await page.check(`#delivery_option_${carrierId}`);
  await page.waitForTimeout(800);
  if (shotPrefix === 'scenario1') {
    await page.screenshot({ path: path.join(SHOTS, 'checkout-delivery-step.png'), fullPage: true });
  }
  await page.click('button[name="confirmDeliveryOption"]');
  await page.waitForSelector('input[data-module-name="ps_wirepayment"]', { timeout: 20000 });
  await page.check('input[data-module-name="ps_wirepayment"]');
  const terms = page.locator('input[name="conditions_to_approve[terms-and-conditions]"]');
  if (await terms.count()) await terms.check();
  await Promise.all([page.waitForURL(/order-confirmation|controller=order-confirmation/, { timeout: 30000 }), page.click('#payment-confirmation button')]);
  const url = new URL(page.url());
  return parseInt(url.searchParams.get('id_order'), 10);
}

(async () => {
  const stock = sql('SELECT id_offer, quantity FROM ps_bxmp_offer WHERE id_offer IN (1,2,3,4,9,10)');
  const browser = await chromium.launch({ headless: !process.env.HEADED });
  const context = await browser.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'en-US' });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  try {
    // ---------------------------------------------------------------- login
    await page.goto(`${BASE}/index.php?controller=authentication`);
    await page.fill('input[name="email"]', CUSTOMER.email);
    await page.fill('input[name="password"]', CUSTOMER.password);
    await Promise.all([page.waitForNavigation(), page.click('#submit-login')]);
    check('customer login', await page.locator('a.logout, .user-info a[href*="mylogout"]').count() > 0);
    await emptyCart(page);

    // ---------------------------------------------------------------- scenario 1: client scenario €35
    await addToCart(page, `${BASE}/index.php?id_product=20&controller=product`, 1, 'checkout-product-offers.png');
    const offers = await page.locator('#bxmpco-offers tbody tr').count();
    const best = await page.locator('#bxmpco-offers tr.is-best').innerText();
    check('product page lists the 3 vendor offers', offers === 3, `${offers} rows`);
    check('best total badge on Vendor A for Product 1', /Vendor A/.test(best));
    await addToCart(page, `${BASE}/index.php?id_product=21&controller=product`, 1);
    let t = await cartTotals(page);
    await page.locator('#bxmpco-allocation details').evaluate((d) => { d.open = true; });
    await page.locator('#bxmpco-allocation').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(SHOTS, 'checkout-cart-allocation.png'), fullPage: true });
    check('cart items = €30 (5 + 25)', near(t.products, 30), JSON.stringify(t));
    check('cart total = €35', near(t.total, 35), `${t.total}`);
    check('allocation block: items €30 / total €35', near(t.allocItems, 30) && near(t.allocTotal, 35));
    const vendorsInCart = await page.locator('#bxmpco-allocation .bxmpco-vendor').allInnerTexts();
    check('allocation: Vendor A (P1, €5 shipping) + Vendor B (P2, free)', vendorsInCart.length === 2 && /Vendor A[\s\S]*Product 1[\s\S]*€5\.00/.test(vendorsInCart[0]) && /Vendor B[\s\S]*Product 2[\s\S]*Free/.test(vendorsInCart[1]), vendorsInCart.join(' | ').replace(/\s+/g, ' '));
    const savings = await page.locator('#bxmpco-allocation .bxmpco-savings').allInnerTexts();
    check('savings vs naive alternatives shown', savings.length >= 1, savings.join(' | '));

    const order1 = await checkout(page, 'scenario1');
    check('order 1 placed', order1 > 0, `id_order=${order1}`);
    await page.waitForSelector('#bxmpco-order-split');
    await page.screenshot({ path: path.join(SHOTS, 'checkout-confirmation.png'), fullPage: true });
    const [ord] = sql(`SELECT reference, total_products, total_shipping_tax_incl, total_paid_tax_incl FROM ps_orders WHERE id_order=${order1}`);
    check('PS order: products €30, shipping €5, paid €35', near(+ord[1], 30) && near(+ord[2], 5) && near(+ord[3], 35), ord.join(' '));
    const vos = sql(`SELECT id_vendor, reference, total_products, total_shipping, total_paid, commission_amount, vendor_payout, payment_transfer_ref FROM ps_bxmp_vendor_order WHERE id_order=${order1} ORDER BY id_vendor`);
    check('2 vendor orders created', vos.length === 2, vos.map((v) => v.slice(0, 7).join('/')).join(' ; '));
    const a = vos.find((v) => v[0] === '1');
    const b = vos.find((v) => v[0] === '2');
    check('vendor A: P1 €5 + €5 shipping, commission 10% = €0.50, payout €9.50', a && near(+a[2], 5) && near(+a[3], 5) && near(+a[4], 10) && near(+a[5], 0.5) && near(+a[6], 9.5));
    check('vendor B: P2 €25, free shipping, commission 12.5% = €3.13, payout €21.87', b && near(+b[2], 25) && near(+b[3], 0) && near(+b[5], 3.13) && near(+b[6], 21.87));
    check('vendor order reference PARENTREF-V{id}', a && a[1] === `${ord[0]}-V1` && b[1] === `${ord[0]}-V2`);
    check('Mangopay (sandbox simulation) transfer refs stored', a && /^mangopay-sim:SIM-TR-.*\|SIM-PAYIN-/.test(a[7]), a && a[7]);
    const sp = sql(`SELECT COUNT(*) FROM ps_specific_price sp JOIN ps_orders o ON o.id_cart = sp.id_cart WHERE o.id_order=${order1}`);
    check('cart-scoped specific prices removed after order', sp[0][0] === '0');
    const lines = sql(`SELECT l.id_product, l.quantity, l.unit_price, l.commission_amount FROM ps_bxmp_vendor_order_line l JOIN ps_bxmp_vendor_order vo ON vo.id_vendor_order=l.id_vendor_order WHERE vo.id_order=${order1} ORDER BY l.id_product`);
    check('vendor order lines: P1 @5 (comm 0.50), P2 @25 (comm 3.13)', lines.length === 2 && near(+lines[0][2], 5) && near(+lines[0][3], 0.5) && near(+lines[1][2], 25) && near(+lines[1][3], 3.13));

    // ---------------------------------------------------------------- scenario 2: quantity split
    // expectations computed from the live offers (vendor prices may be changed by CSV feeds)
    const [[pA, sA], [pB, sB]] = sql('SELECT price, quantity FROM ps_bxmp_offer WHERE id_offer IN (9,10) ORDER BY id_offer').map((r) => r.map(Number));
    const qB = Math.min(5, sB); const qA = 5 - qB;
    const expSplit = Math.round((qB * pB + qA * pA) * 100) / 100;
    const splitLabel = `${qB}×${pB.toFixed(2)} B + ${qA}×${pA.toFixed(2)} A = €${expSplit.toFixed(2)}`;
    await emptyCart(page);
    await addToCart(page, `${BASE}/index.php?id_product=1&id_product_attribute=2&controller=product`, 5, 'checkout-product-offers-combination.png');
    t = await cartTotals(page);
    await page.locator('#bxmpco-allocation details').evaluate((d) => { d.open = true; });
    await page.screenshot({ path: path.join(SHOTS, 'checkout-cart-quantity-split.png'), fullPage: true });
    check(`split: allocation items ${splitLabel}, free shipping`, pB < pA && qA > 0 && near(t.allocItems, expSplit) && /Free/i.test(t.allocShipping), JSON.stringify(t));
    check(`split: native cart products total equals optimized €${expSplit.toFixed(2)}`, near(t.products, expSplit), `${t.products}`);
    const splitBadge = await page.locator('#bxmpco-allocation .bxmpco-vendor .bxmpco-badge--off').count();
    check('split: line flagged as split across 2 vendors', splitBadge === 2, `${splitBadge}`);

    // ---------------------------------------------------------------- scenario 3: blocking (vendor stock drops after add-to-cart)
    // PS native stock (synced to Σ offers) already refuses qty > stock at add-to-cart; the marketplace-specific case is
    // vendor stock changing while the item sits in the cart: vendor A sells out down to 1 → only sB+1 < 5 available.
    sql('UPDATE ps_bxmp_offer SET quantity=1 WHERE id_offer=9');
    await page.goto(`${BASE}/index.php?controller=cart&action=show`);
    const blocked = await page.locator('#bxmpco-blocked').count();
    const disabled = await page.locator('.cart-detailed-actions button.disabled, .cart-detailed-actions .btn.disabled').count();
    check('vendor stock dropped below cart qty: checkout blocked with a message (no substitution)', blocked === 1 && disabled >= 1, (await page.locator('.cart-detailed-actions').innerText()).replace(/\s+/g, ' ').slice(0, 220));
    await page.screenshot({ path: path.join(SHOTS, 'checkout-cart-blocked.png'), fullPage: true });
    await page.goto(`${BASE}/index.php?controller=order`);
    check('direct access to checkout redirects back to the cart', /controller=cart|\/cart/.test(page.url()), page.url());
    sql(`UPDATE ps_bxmp_offer SET quantity=${sA} WHERE id_offer=9`);
    t = await cartTotals(page);
    check(`vendor stock restored: unblocked, €${expSplit.toFixed(2)} again`, near(t.products, expSplit) && (await page.locator('#bxmpco-blocked').count()) === 0, `${t.products}`);

    const order2 = await checkout(page, 'scenario2');
    const [ord2] = sql(`SELECT reference, total_products, total_shipping_tax_incl FROM ps_orders WHERE id_order=${order2}`);
    check(`order 2 (split) placed: products €${expSplit.toFixed(2)}, shipping €0`, near(+ord2[1], expSplit) && near(+ord2[2], 0), ord2.join(' '));
    const vos2 = sql(`SELECT vo.id_vendor, l.quantity, l.unit_price, vo.total_products, vo.commission_amount FROM ps_bxmp_vendor_order vo JOIN ps_bxmp_vendor_order_line l ON l.id_vendor_order=vo.id_vendor_order WHERE vo.id_order=${order2} ORDER BY vo.id_vendor`);
    const c2 = (v) => Math.round(v * 100 + 1e-7) / 100;
    check(`split order: A ${qA}×${pA} (10% = ${c2(qA * pA * 0.1)}), B ${qB}×${pB} (12.5% = ${c2(qB * pB * 0.125)})`,
      vos2.length === 2 && vos2[0][0] === '1' && +vos2[0][1] === qA && near(+vos2[0][2], pA) && near(+vos2[0][4], c2(qA * pA * 0.1))
      && vos2[1][0] === '2' && +vos2[1][1] === qB && near(+vos2[1][2], pB) && near(+vos2[1][4], c2(qB * pB * 0.125)), vos2.map((v) => v.join('/')).join(' ; '));
    await page.screenshot({ path: path.join(SHOTS, 'checkout-confirmation-split.png'), fullPage: true });

    // ---------------------------------------------------------------- back office: vendor split panel
    const admin = await context.newPage();
    await admin.goto(`${BASE}/admin-dev/index.php?controller=AdminLogin`);
    await admin.fill('#email', ADMIN.email);
    await admin.fill('#passwd', ADMIN.password);
    await Promise.all([admin.waitForNavigation({ timeout: 30000 }), admin.click('#submit_login')]);
    await admin.waitForLoadState('domcontentloaded');
    // token from the post-login redirect URL (works even if a dashboard widget fails to render)
    const token = new URL(admin.url()).searchParams.get('_token') || ((await admin.content()).match(/[?&]_token=([\w.-]+)/) || [])[1] || '';
    await admin.goto(`${BASE}/admin-dev/index.php/sell/orders/${order1}/view?_token=${token}`);
    await admin.waitForSelector('#bxmpcoVendorSplit');
    const form = admin.locator('#bxmpcoVendorSplit form').first();
    await form.locator('select[name="status"]').selectOption('shipped');
    await form.locator('input[name="carrier_name"]').fill('BRT');
    await form.locator('input[name="tracking_number"]').fill('TRK-A-0001');
    await Promise.all([admin.waitForNavigation({ timeout: 30000 }), form.locator('button[name="submitBxmpVendorOrder"]').click()]);
    await admin.waitForSelector('#bxmpcoVendorSplit');
    const upd = sql(`SELECT status, carrier_name, tracking_number FROM ps_bxmp_vendor_order WHERE id_order=${order1} AND id_vendor=1`);
    check('BO: vendor A sub-order set to shipped with tracking (independent of vendor B)', upd[0] && upd[0][0] === 'shipped' && upd[0][2] === 'TRK-A-0001'
      && sql(`SELECT status FROM ps_bxmp_vendor_order WHERE id_order=${order1} AND id_vendor=2`)[0][0] === 'pending', upd[0] && upd[0].join('/'));
    await admin.locator('#bxmpcoVendorSplit').scrollIntoViewIfNeeded();
    await admin.screenshot({ path: path.join(SHOTS, 'checkout-bo-order-vendor-split.png'), fullPage: false });
    await admin.locator('#bxmpcoVendorSplit').screenshot({ path: path.join(SHOTS, 'checkout-bo-vendor-split-panel.png') });

    // customer order detail shows the per-vendor tracking
    await page.goto(`${BASE}/index.php?controller=history`);
    await Promise.all([page.waitForNavigation(), page.locator(`a[href*="id_order=${order1}"]`).first().click()]);
    await page.locator('#bxmpco-order-split').scrollIntoViewIfNeeded().catch(() => {});
    await page.screenshot({ path: path.join(SHOTS, 'checkout-order-detail-tracking.png'), fullPage: false });
    const detail = await page.locator('#bxmpco-order-split').innerText().catch(() => '');
    check('FO order detail shows per-vendor status + tracking', /shipped/i.test(detail) && /TRK-A-0001/.test(detail));
    check('no JS errors on storefront pages', errors.length === 0, errors.join(' | '));
  } catch (e) {
    check('e2e run without exception', false, e.stack || String(e));
    await page.screenshot({ path: path.join(ROOT, 'docs/screenshots/checkout-e2e-failure.png'), fullPage: true }).catch(() => {});
  } finally {
    await emptyCart(page).catch(() => {});
    await browser.close();
    for (const [id, q] of stock) sql(`UPDATE ps_bxmp_offer SET quantity=${parseInt(q, 10)} WHERE id_offer=${parseInt(id, 10)}`);
  }
  console.log(`\n${results.length - failures}/${results.length} checks passed`);
  process.exit(failures ? 1 : 0);
})();
