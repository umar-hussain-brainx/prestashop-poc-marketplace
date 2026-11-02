/**
 * Browser check of the collections front office (+ BO screenshots).
 *   NODE_PATH=$(npm root -g) node modules/bxmpcollections/tests/e2e/collections.e2e.js [screenshotDir]
 * Env: SHOP_URL (default http://localhost:8080), BO_EMAIL / BO_PASSWD.
 * Expects the demo collections (tools/seed_collections.php): live = christmas, breakfast, aperitivo;
 * hidden = easter (scheduled), halloween + all-saints-day (expired), carnival (scheduled).
 */
const { chromium } = require('playwright');
const assert = require('assert');

const SHOP = process.env.SHOP_URL || 'http://localhost:8080';
const OUT = process.argv[2] || 'docs/screenshots';
const LIVE = ['christmas', 'breakfast', 'aperitivo'];
const HIDDEN = ['easter', 'halloween', 'carnival', 'all-saints-day'];

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
  let failures = 0;
  const check = async (name, fn) => {
    try { await fn(); console.log('ok   ' + name); } catch (e) { failures++; console.log('FAIL ' + name + ': ' + e.message); }
  };

  await page.goto(SHOP + '/');
  await check('homepage block lists only live collections', async () => {
    const slugs = await page.$$eval('#bxmp-home-collections .bxmp-collection-card', els => els.map(e => e.dataset.slug));
    assert.deepStrictEqual([...slugs].sort(), [...LIVE].sort());
  });
  await check('top menu strip lists only live collections', async () => {
    const slugs = await page.$$eval('#bxmp-collections-nav a[data-slug]', els => els.map(e => e.dataset.slug));
    assert.deepStrictEqual([...slugs].sort(), [...LIVE].sort());
    for (const h of HIDDEN) assert.ok(!slugs.includes(h), h + ' must be hidden');
  });
  await page.locator('#bxmp-home-collections').scrollIntoViewIfNeeded();
  await page.screenshot({ path: OUT + '/collections-home.png', fullPage: true });

  await check('collection page renders products with the theme miniature', async () => {
    const res = await page.goto(SHOP + '/collection/christmas');
    assert.strictEqual(res.status(), 200);
    assert.strictEqual((await page.textContent('h1')).trim(), 'Christmas');
    const n = await page.$$eval('#bxmp-collection .products article.product-miniature', els => els.length);
    assert.ok(n >= 3, 'expected products, got ' + n);
    assert.ok(await page.$('#bxmp-collection .product-price-and-shipping, #bxmp-collection .price'), 'price block');
  });
  await page.screenshot({ path: OUT + '/collections-page.png', fullPage: true });

  for (const slug of HIDDEN) {
    await check('hidden collection ' + slug + ' answers 404', async () => {
      const res = await page.goto(SHOP + '/collection/' + slug);
      assert.strictEqual(res.status(), 404);
    });
  }
  await check('collections index lists live collections', async () => {
    await page.goto(SHOP + '/collections');
    const slugs = await page.$$eval('#content .bxmp-collection-card', els => els.map(e => e.dataset.slug));
    assert.deepStrictEqual([...slugs].sort(), [...LIVE].sort());
  });

  // back office screenshots
  if (!process.env.SKIP_BO) {
    await page.goto(SHOP + '/admin-dev/');
    await page.fill('#email', process.env.BO_EMAIL || 'admin@marketplace.test');
    await page.fill('#passwd', process.env.BO_PASSWD || 'Admin123!');
    await Promise.all([page.waitForNavigation(), page.click('#submit_login')]);
    const links = await page.$$eval('a[href*="controller=AdminBxmp"]', as => Object.fromEntries(as.map(a => [new URL(a.href).searchParams.get('controller'), a.href])));
    await check('BO collections list shows schedule statuses', async () => {
      await page.goto(links.AdminBxmpCollections);
      const statuses = await page.$$eval('.bxmp-status', els => els.map(e => e.dataset.status));
      assert.ok(statuses.includes('live') && statuses.includes('scheduled') && statuses.includes('expired'), statuses.join(','));
    });
    await page.screenshot({ path: OUT + '/collections-admin-list.png', fullPage: true });
    await check('BO collection edit: product search + preview', async () => {
      await page.goto(links.AdminBxmpCollections + '&id_collection=1&updatebxmp_collection&product_search=mug');
      assert.ok((await page.$$('#bxmp-search-results tbody tr')).length >= 1);
      assert.ok((await page.textContent('#bxmp-collection-preview')).includes('resolved product'));
    });
    await page.screenshot({ path: OUT + '/collections-admin-edit.png', fullPage: true });
    await check('BO custom categories tree', async () => {
      await page.goto(links.AdminBxmpCategories);
      assert.ok((await page.$$('#bxmp-category-tree tbody tr')).length >= 5);
    });
    await page.screenshot({ path: OUT + '/collections-admin-categories.png', fullPage: true });
  }

  await browser.close();
  console.log(failures ? failures + ' failure(s)' : 'all checks passed');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
