const path = require('path');
const { test, expect } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const requestJs = path.join(root, 'public/assets/js/MubloRequest.js');
const coreJs = path.join(root, 'public/assets/js/MubloCore.js');
const formJs = path.join(root, 'public/assets/js/MubloForm.js');
const modalJs = path.join(root, 'public/assets/js/MubloModal.js');
const payload = '<img src=x onerror="window.__xss=true">사용자 입력';

test('MubloRequest text dialogs do not compile user HTML', async ({ page }) => {
  await page.setContent('<button id="before">before</button>');
  await page.addScriptTag({ path: requestJs });
  await page.evaluate(value => {
    window.__xss = false;
    MubloRequest.showAlert(value, 'warning');
  }, payload);

  await expect(page.locator('.mublo-alert__msg')).toHaveText(payload);
  await expect(page.locator('.mublo-alert__msg img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__xss)).toBe(false);
});

test('MubloForm creates option labels as text', async ({ page }) => {
  await page.setContent('<select id="target"></select>');
  await page.addScriptTag({ path: coreJs });
  await page.addScriptTag({ path: formJs });
  await page.evaluate(value => {
    window.__xss = false;
    MubloForm.util.populateSelect('target', [{ value: '1', text: value }]);
  }, payload);

  await expect(page.locator('#target option').nth(1)).toHaveText(payload);
  await expect(page.locator('#target img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__xss)).toBe(false);
});

test('MubloModal alert escapes message content', async ({ page }) => {
  await page.setContent('<main>page</main>');
  await page.addScriptTag({ path: modalJs });
  await page.evaluate(value => {
    window.__xss = false;
    MubloModal.alert(value);
  }, payload);

  await expect(page.locator('.customModal-body')).toHaveText(payload);
  await expect(page.locator('.customModal-body img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__xss)).toBe(false);
});

test('MubloRequest fetches CSRF only for state-changing requests', async ({ page }) => {
  let csrfRequests = 0;
  let mutationToken = '';
  await page.route('**/api/v1/csrf/token', async route => {
    csrfRequests += 1;
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ result: 'success', data: { token: 'csrf-test-token' } }),
    });
  });
  await page.route(/\/query(?:\?.*)?$/, async route => {
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ result: 'success', data: {} }) });
  });
  await page.route(/\/mutate(?:\?.*)?$/, async route => {
    mutationToken = route.request().headers()['x-csrf-token'] || '';
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ result: 'success', data: {} }) });
  });
  await page.route('http://mublo.test/test-page', async route => {
    await route.fulfill({ contentType: 'text/html', body: '<main>page</main>' });
  });
  await page.goto('http://mublo.test/test-page');
  await page.addScriptTag({ path: requestJs });

  await page.evaluate(async () => {
    await MubloRequest.requestQuery('/query');
    await MubloRequest.requestJson('/mutate', { value: 1 });
  });

  expect(csrfRequests).toBe(1);
  expect(mutationToken).toBe('csrf-test-token');
});
