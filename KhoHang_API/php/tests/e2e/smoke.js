// Requires Playwright (npm i -g playwright or NODE_PATH pointing to it) and a running stack (run.sh).
const { chromium } = require('playwright');
const APP = 'http://127.0.0.1:5173';
(async () => {
  const out = process.argv[2] || '.';
  const problems = [], checks = [];
  const b = await chromium.launch();
  const page = await b.newPage({ viewport: { width: 1400, height: 900 } });
  page.on('pageerror', e => problems.push(`pageerror ${page.url()}: ${e.message}`));
  let provoking = false; // console errors are expected while we provoke a failure on purpose
  page.on('console', m => { if (m.type() === 'error' && !provoking) problems.push(`console ${page.url()}: ${m.text().slice(0, 200)}`); });
  const expected = new Set(); // 4xx we provoke on purpose
  page.on('response', r => {
    const key = `${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`;
    if (r.url().includes(':8000') && r.status() >= 400 && !expected.has(key)) problems.push('http ' + key);
  });
  const check = (name, ok) => { checks.push(`${ok ? 'PASS' : 'FAIL'} ${name}`); if (!ok) problems.push('check failed: ' + name); };
  // Wait until the page's API calls finish: a fixed delay aborts in-flight requests on slower
  // single-threaded servers (php -S on Windows) and shows up as bogus 'Failed to fetch' errors.
  const open = async (url) => { await page.goto(url); await page.waitForLoadState('networkidle'); };

  await page.goto(APP + '/login');
  await page.fill('input[placeholder*="username"]', 'admin');
  await page.fill('input[type="password"]', 'admin123');
  await page.keyboard.press('Enter');
  await page.waitForURL('**/dashboard', { timeout: 15000 });
  await page.waitForLoadState('networkidle');
  check('login reaches dashboard', true);

  for (const p of ['/dashboard', '/items', '/items/tracking', '/items/alerts', '/stock', '/stock/in', '/stock/out', '/suppliers', '/warehouses', '/reports']) {
    await open(APP + p);
    await page.screenshot({ path: `${out}/${p.slice(1).replace(/\//g, '_')}.png` });
  }

  await open(APP + '/items');
  check('items list shows stock quantity', /\b2\s*\/\s*5\b/.test(await page.locator('tbody tr').first().innerText()));

  await open(APP + '/reports');
  check('inventory report shows money value', (await page.locator('body').innerText()).includes('280.000₫'));

  const passkey = async (code) => {
    const inputs = page.locator('input[maxlength="1"]');
    await inputs.first().waitFor({ timeout: 5000 });
    for (let i = 0; i < 6; i++) await inputs.nth(i).fill(code[i]);
    await page.getByRole('button', { name: /Xác nhận|Xác thực/ }).last().click();
    await page.waitForTimeout(1500);
  };
  await open(APP + '/warehouses');
  await page.locator('tbody tr').first().locator('button').first().click();
  await page.locator('input[value="Kho chính"]').fill('Kho chính (sửa)');
  expected.add('403 PUT /warehouses/1');
  provoking = true;
  await page.getByRole('button', { name: /Cập nhật|Lưu/ }).last().click();
  await passkey('000000');
  provoking = false;
  check('wrong passkey is rejected by the API', (await page.locator('body').innerText()).includes('Passkey không hợp lệ'));
  await page.getByRole('button', { name: /Cập nhật|Lưu/ }).last().click();
  await passkey('246810');
  check('correct passkey updates warehouse', (await page.locator('tbody tr').first().innerText()).includes('Kho chính (sửa)'));

  await open(APP + '/change-password');
  const pw = page.locator('input[type="password"]');
  await pw.nth(0).fill('admin123'); await pw.nth(1).fill('admin456!'); await pw.nth(2).fill('admin456!');
  await page.getByRole('button', { name: /Đổi mật khẩu/ }).last().click();
  await page.waitForURL('**/login', { timeout: 10000 }).catch(() => {});
  check('change password logs out', page.url().endsWith('/login'));
  await page.fill('input[placeholder*="username"]', 'admin');
  await page.fill('input[type="password"]', 'admin456!');
  await page.keyboard.press('Enter');
  await page.waitForURL('**/dashboard', { timeout: 15000 }).catch(() => {});
  check('login with the new password', page.url().endsWith('/dashboard'));

  console.log(checks.join('\n'));
  if (problems.length) { console.log('PROBLEMS:\n' + [...new Set(problems)].join('\n')); process.exitCode = 1; }
  await b.close();
})();
