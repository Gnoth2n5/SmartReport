import { expect, type Page, test } from '@playwright/test';

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/admin/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

test('SIT reader can find authorized cards and load private evidence', async ({ page }) => {
    await login(page, 'reader@smartreport.test');
    await page.getByRole('link', { name: 'Kịch bản SIT', exact: true }).click();
    await expect(page.getByText('Kiểm Thử SIT Đồng Bộ Đơn Hàng & Lệnh Sản Xuất ERP', { exact: true })).toBeVisible();
    await expect(page.getByText('Private SIT', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Tạo kịch bản SIT' })).toHaveCount(0);
    await page.getByRole('link', { name: 'Chạy SIT', exact: true }).click();
    await expect(page.locator('.sit-img-card img')).toHaveCount(1);
    await expect.poll(() => page.locator('.sit-img-card img').evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    const response = await page.goto('/admin/sit-templates/create');
    expect(response?.status()).toBe(403);
});

test('SIT native card grid works on desktop and mobile', async ({ page }) => {
    await login(page, 'admin@smartreport.test');
    await page.goto('/admin/sit-templates');
    await expect(page.getByRole('link', { name: 'Chạy SIT', exact: true })).toHaveCount(2);
    await page.screenshot({ path: test.info().outputPath('sit-grid-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expect(page.getByText('Private SIT', { exact: true })).toBeVisible();
    const fits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    expect(fits).toBe(true);
    await page.screenshot({ path: test.info().outputPath('sit-grid-mobile.png'), fullPage: true });
});

test('SIT stale tab shows conflict and reloads the saved result', async ({ page, context }) => {
    await login(page, 'admin@smartreport.test');
    await page.goto('/admin/sit-tests/sit-erp-order-sync');
    const other = await context.newPage();
    await other.goto('/admin/sit-tests/sit-erp-order-sync');
    await page.locator('#sit-summary-notes').fill('Saved in first tab');
    await page.getByRole('button', { name: 'Lưu kết quả', exact: true }).click();
    await expect(page.locator('.fi-no-notification').filter({ hasText: 'Đã lưu kết quả SIT' })).toBeVisible();
    await other.locator('#sit-summary-notes').fill('Stale second tab');
    await other.getByRole('button', { name: 'Lưu kết quả', exact: true }).click();
    await expect(other.getByRole('alert')).toContainText('Đợt test đã được cập nhật ở nơi khác');
    await other.getByRole('button', { name: 'Tải lại đợt test', exact: true }).click();
    await expect(other.locator('#sit-summary-notes')).toHaveValue('Saved in first tab');
});
