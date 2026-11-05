import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.DIGICYFR_PLAYWRIGHT_MODULE || 'playwright-core');
const browser = await chromium.launch({
  ...(process.env.DIGICYFR_CHROMIUM_PATH ? { executablePath: process.env.DIGICYFR_CHROMIUM_PATH } : {}),
  headless: true,
  args: ['--no-sandbox'],
});
const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await context.newPage();
const base = process.env.DIGICYFR_BROWSER_BASE;
assert(base, 'DIGICYFR_BROWSER_BASE missing');

async function checkAccessibility(selector) {
  if (!process.env.DIGICYFR_AXE_PATH) return;
  await page.addScriptTag({ path: process.env.DIGICYFR_AXE_PATH });
  const result = await page.evaluate(async (target) => window.axe.run(target), selector);
  const findings = result.violations.map(({ id, nodes }) => `${id}: ${nodes.map(({ target }) => target.join(' ')).join(', ')}`);
  assert.equal(findings.length, 0, `Accessibility findings in ${selector}: ${findings.join('; ')}`);
  console.log(`axe ${selector}: 0 violations; ${result.incomplete.length} incomplete checks`);
}

try {
  await page.goto(base, { waitUntil: 'networkidle' });
  const form = page.locator('.digicyfr-quote-desk form');
  assert.equal(await form.count(), 1, 'Public form not found');
  await checkAccessibility('.digicyfr-quote-desk');
  await form.locator('[name="service"]').focus();
  assert.equal(await form.locator('[name="service"]').evaluate((el) => el === document.activeElement), true, 'Service field did not receive keyboard focus');
  await form.locator('[name="service"]').fill('Repair');
  await form.locator('[name="location"]').fill('Warszawa');
  await form.locator('[name="details"]').fill('Repair a window frame');
  await form.locator('[name="customer_name"]').fill('Browser Customer');
  await form.locator('button[type="submit"]').click();
  const validation = await form.locator('[name="email"]').evaluate((el) => el.validationMessage);
  assert(validation.includes('email') || validation.includes('phone'), 'Contact validation did not run');
  await form.locator('[name="email"]').fill('browser@example.invalid');
  await form.locator('button[type="submit"]').click();
  await page.waitForURL(/digicyfr_quote=success/);
  assert(await page.getByText('Thank you. Your request has been saved.').isVisible(), 'Success message missing');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  assert(!overflow, 'Public form overflows mobile viewport');

  await page.goto(`${base}/wp-login.php`);
  await page.locator('#user_login').fill('digicyfr_test_admin');
  await page.locator('#user_pass').fill('test-only-password');
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
  await page.goto(`${base}/wp-admin/admin.php?page=digicyfr-quote-desk`);
  const request = page.getByRole('link', { name: /Browser Customer/ });
  assert(await request.isVisible(), 'Saved request not listed in admin');
  await request.click();
  assert(await page.getByText('Repair a window frame').isVisible(), 'Request detail missing');
  await page.locator('#digicyfr-quote-status').selectOption('contacted');
  await page.locator('#digicyfr-quote-note').fill('Call scheduled');
  await page.getByRole('button', { name: 'Save request' }).click();
  await page.waitForURL(/updated=1/);
  assert(await page.getByText('Request updated.').isVisible(), 'Admin update did not complete');
  await checkAccessibility('.digicyfr-quote-admin');
  console.log('Browser public form, mobile width and admin follow-up verified');
} finally {
  await browser.close();
}
