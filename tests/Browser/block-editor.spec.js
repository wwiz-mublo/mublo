const { test, expect } = require('@playwright/test');

test('anonymous request cannot unlock an inactive page with preview=1', async ({ page }) => {
  const pageCode = process.env.MUBLO_E2E_INACTIVE_PAGE_CODE;
  test.skip(!pageCode, 'MUBLO_E2E_INACTIVE_PAGE_CODE is required for the inactive-page fixture.');

  const response = await page.goto(`/p/${encodeURIComponent(pageCode)}?preview=1`);

  expect(response).not.toBeNull();
  expect(response.status()).toBe(404);
  await expect(page.locator('body')).not.toContainText('block-placeholder');
});

test.describe('authenticated block editor', () => {
  test.skip(
    !process.env.MUBLO_E2E_ADMIN_ID || !process.env.MUBLO_E2E_ADMIN_PASSWORD,
    'MUBLO_E2E_ADMIN_ID and MUBLO_E2E_ADMIN_PASSWORD are required.'
  );

  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/login');
    await page.locator('#user_id').fill(process.env.MUBLO_E2E_ADMIN_ID);
    await page.locator('#password').fill(process.env.MUBLO_E2E_ADMIN_PASSWORD);
    await Promise.all([
      page.waitForURL(url => !url.pathname.endsWith('/admin/login')),
      page.locator('form').getByRole('button', { name: '로그인' }).click(),
    ]);
  });

  test('editor shell, preview frames and extracted stylesheet load', async ({ page }) => {
    const stylesheet = page.waitForResponse(response =>
      response.url().includes('/assets/css/admin/block-editor.css') && response.ok()
    );

    await page.goto('/admin/block-editor');

    await expect(page.getByText('블록 에디터', { exact: true }).first()).toBeVisible();
    await expect(page.locator('#bkeIframe')).toHaveAttribute('title', '미리보기');
    await expect(page.locator('#bkeEditIframe')).toHaveAttribute('title', '행 설정');
    await stylesheet;
  });

  test('row edit exposes the optimistic-lock token and revision history control', async ({ page }) => {
    const rowId = process.env.MUBLO_E2E_BLOCK_ROW_ID;
    test.skip(!rowId, 'MUBLO_E2E_BLOCK_ROW_ID is required for the row fixture.');

    await page.goto(`/admin/block-row/edit?id=${encodeURIComponent(rowId)}&_embed=1`);

    const revision = page.locator('input[name="formData[revision_no]"]');
    await expect(revision).toHaveValue(/^\d+$/);
    await expect(page.locator('#rowRevisionButton')).toBeVisible();
  });

  test('block row list exposes recoverable deletion history', async ({ page }) => {
    await page.goto('/admin/block-row');

    await expect(page.getByRole('button', { name: '삭제 이력' })).toBeVisible();
  });
});
