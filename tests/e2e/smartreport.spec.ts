import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Locator, type Page, test } from '@playwright/test';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const SCREENSHOTS_DIR = path.join(PROJECT_ROOT, '.agents', 'docs', 'screenshots');
const DUCKDB_BIN = path.join(PROJECT_ROOT, 'storage', 'bin', 'duckdb');

async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    if (!page.url().includes('/admin/login')) {
        return;
    }

    await page.locator('input[type="email"]').fill('admin@smartreport.test');
    await page.locator('input[type="password"]').fill('password');
    await page.locator('button[type="submit"]').click();

    await page.waitForURL((url) => !url.pathname.includes('/login'));
    await expect(page.locator('nav.fi-sidebar-nav')).toBeVisible();
}

async function selectFilamentOption(page: Page, fieldIdPrefix: string, optionLabel: string): Promise<void> {
    const fieldWrapper = page.locator(`[id*="${fieldIdPrefix}"]`).first();

    // Handle native <select> if not searchable/Choices-based
    const tagName = await fieldWrapper.evaluate((el) => el.tagName.toLowerCase());
    if (tagName === 'select') {
        const isEnhanced = await fieldWrapper.evaluate((el) => el.classList.contains('choices__input') || el.closest('.choices') !== null);
        if (!isEnhanced) {
            await fieldWrapper.selectOption({ label: optionLabel });
            return;
        }
    }

    // Filament v5 Choices / Alpine Select wrapper
    const container = page.locator(`.fi-fo-field:has([id*="${fieldIdPrefix}"])`).first();
    const trigger = container.locator('.fi-select-input-btn, [role="combobox"], .choices__inner, input[type="search"]').first();
    await trigger.click();

    const searchInput = container.locator('input[type="search"], .choices__input--cloned').first();
    if (await searchInput.isVisible()) {
        await searchInput.fill(optionLabel);
    }

    const option = page.locator('[role="option"], .fi-select-input-option, .choices__item--selectable').filter({ hasText: optionLabel }).first();
    await expect(option).toBeVisible();
    await option.click();
}

async function waitForFilamentNotification(page: Page, expectedText: string): Promise<Locator> {
    const notification = page.locator('.fi-no-notification').filter({ hasText: expectedText }).last();
    await expect(notification).toBeVisible({ timeout: 30_000 });
    return notification;
}

test.describe.serial('SmartReport E2E Verification Suite', () => {
    let tempDownloadDir: string;

    test.beforeAll(() => {
        fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
        tempDownloadDir = fs.mkdtempSync(path.join(os.tmpdir(), 'smartreport-e2e-'));

        // Keep Scenario 2 idempotent across repeated E2E runs
        execFileSync(
            'php',
            [
                'artisan',
                'tinker',
                '--execute=\\App\\Models\\CustomView::where("view_code", "e2e-dynamic-orders")->delete();',
            ],
            { cwd: PROJECT_ROOT, stdio: 'pipe' }
        );
    });

    test.afterAll(() => {
        if (tempDownloadDir && fs.existsSync(tempDownloadDir)) {
            fs.rmSync(tempDownloadDir, { recursive: true, force: true });
        }
    });

    test('E2E 1: Data Sources live connectivity across all 4 DBs & strict 3-driver check', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/data-sources');

        const expectedSources = [
            { code: 'pg_north', driver: 'pgsql' },
            { code: 'pg_south', driver: 'pgsql' },
            { code: 'mssql_erp', driver: 'sqlsrv' },
            { code: 'oracle_hr', driver: 'oracle' },
        ];

        for (const src of expectedSources) {
            const row = page.locator('tr').filter({ hasText: src.code }).first();
            await expect(row).toBeVisible();
        }

        for (const src of expectedSources) {
            const row = page.locator('tr').filter({ hasText: src.code }).first();
            await row.getByRole('button', { name: 'Kiểm tra kết nối' }).click();

            const notif = await waitForFilamentNotification(page, 'Kết nối thành công');
            await expect(notif).toContainText(src.driver);
            await expect(notif).toContainText('ms');

            if (src.code === 'oracle_hr') {
                await page.screenshot({
                    path: path.join(SCREENSHOTS_DIR, '01-data-sources-test-connection.png'),
                    fullPage: true,
                });
            }

            const closeBtn = notif.locator('button').first();
            if (await closeBtn.isVisible()) {
                await closeBtn.click();
            }
        }

        // Verify DataSourceResource driver options are strictly sqlsrv, pgsql, oracle
        await page.goto('/admin/data-sources/create');
        const driverSelect = page.locator('select[id*="driver"]').first();
        await expect(driverSelect).toBeVisible();
        const optionValues = await driverSelect.locator('option').evaluateAll((opts) =>
            opts
                .map((o) => (o as HTMLOptionElement).value)
                .filter((v) => v !== '')
                .sort()
        );
        expect(optionValues).toEqual(['oracle', 'pgsql', 'sqlsrv']);
    });

    test('E2E 2: Custom View Builder auto-detects @params, previews 10 rows via DuckDB, and updates sidebar', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/custom-views/create');

        await page.locator('input[id*="view_code"]').fill('e2e-dynamic-orders');
        await page.locator('input[id*="view_name"]').fill('E2E Dynamic Orders');

        await selectFilamentOption(page, 'menu_group_code', 'Sản xuất & Vận hành');
        await selectFilamentOption(page, 'allowed_sources', 'SQL Server ERP');
        await selectFilamentOption(page, 'default_sources', 'SQL Server ERP');

        // Close any open dropdown before filling SQL
        await page.keyboard.press('Escape');

        const sqlTextarea = page.locator('textarea[id*="sql_text"]').first();
        await sqlTextarea.fill(
            'SELECT order_no, contract_no, plant, output_qty, unit_cost, shift_date FROM dbo.production_orders WHERE (@Plant IS NULL OR plant = @Plant) AND (@StartDate IS NULL OR shift_date >= @StartDate)'
        );
        await sqlTextarea.blur();

        // Trigger Auto-detect @params
        await page.getByRole('button', { name: 'Tự động nhận diện @tham số' }).first().click();
        await waitForFilamentNotification(page, 'Đã nhận diện tham số');

        const filterInputs = page.locator('input[id*="filters"][id*="name"]');
        await expect(filterInputs).toHaveCount(2);
        await expect(filterInputs.nth(0)).toHaveValue('Plant');
        await expect(filterInputs.nth(1)).toHaveValue('StartDate');

        // Trigger 10-row Preview modal via DuckDB
        await page.getByRole('button', { name: 'Chạy thử SQL (Test 10 dòng)' }).first().click();

        const modal = page.locator('.fi-modal.fi-modal-open .fi-modal-window').filter({ hasText: 'Kết quả chạy thử SQL (10 dòng đầu tiên)' });
        await expect(modal).toBeVisible({ timeout: 30_000 });
        await expect(modal).toContainText('10 / 10 dòng');
        await expect(modal).toContainText('ms');
        await expect(modal.locator('table tbody tr')).toHaveCount(10);

        await page.screenshot({
            path: path.join(SCREENSHOTS_DIR, '02-custom-view-builder-preview.png'),
            fullPage: true,
        });

        await modal.getByRole('button', { name: 'Đóng' }).click();
        await expect(modal).toBeHidden();

        // Save Custom View
        await page.getByRole('button', { name: 'Create', exact: true }).click();
        await page.waitForURL(/\/admin\/custom-views(\/\d+\/edit)?$/, { timeout: 30_000 });

        // Confirm sidebar navigation updated dynamically
        await page.goto('/admin');
        await expect(page.locator('nav.fi-sidebar-nav')).toContainText('E2E Dynamic Orders');
    });

    test('E2E 3: Verify all 4 Multi-DB SELECT patterns and 3-tab inspector on ReportRunnerPage', async ({ page }) => {
        await loginAsAdmin(page);

        // Pattern 1A: Single-DB SQL Server
        await page.goto('/admin/reports/mssql-production-orders');
        await expect(page.locator('.fi-pagination-overview')).toContainText('Trang 1 / 4 (Tổng số: 35 dòng)');
        await expect(page.locator('table.fi-ta-table tbody tr')).toHaveCount(10);

        // Pattern 1B: Single-DB Oracle + department filter
        await page.goto('/admin/reports/oracle-hr-directory');
        await expect(page.locator('.fi-pagination-overview')).toContainText('Trang 1 / 3 (Tổng số: 30 dòng)');
        await expect(page.locator('table.fi-ta-table tbody tr')).toHaveCount(10);

        await page.locator('#sr-filter-DeptCode').selectOption('PRD');
        await page.getByRole('button', { name: 'Tra cứu' }).click();
        await expect(page.locator('.fi-pagination-overview')).not.toContainText('Tổng số: 30 dòng');
        await expect(page.locator('table.fi-ta-table tbody')).toContainText('Phòng Sản Xuất & May Mặc');

        // Pattern 2: Multi-DB UNION ALL (pg_north + pg_south) + Snapshot Pagination Page 2
        await page.goto('/admin/reports/pg-multi-plant-contracts');
        await expect(page.locator('.fi-pagination-overview')).toContainText('Trang 1 / 3 (Tổng số: 25 dòng)');

        const pgSouthCheckbox = page.locator('input[type="checkbox"][value="pg_south"]');
        await pgSouthCheckbox.check();
        await page.getByRole('button', { name: 'Tra cứu' }).click();

        await expect(page.locator('.fi-pagination-overview')).toContainText('Trang 1 / 5 (Tổng số: 45 dòng)');
        await expect(page.locator('table.fi-ta-table thead')).toContainText('_source_db');

        // Page 2 from snapshot (rows 11..20)
        await page.getByRole('button', { name: 'Trang sau' }).click();
        await expect(page.locator('.fi-pagination-overview')).toContainText('Trang 2 / 5 (Tổng số: 45 dòng)');
        await expect(page.locator('table.fi-ta-table tbody tr').first().locator('td').first()).toHaveText('11');
        await expect(page.locator('table.fi-ta-table tbody tr')).toHaveCount(10);

        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({
            path: path.join(SCREENSHOTS_DIR, '03-report-runner-union-pg.png'),
            fullPage: true,
        });

        // Pattern 3: Cross-Engine 2-DB JOIN (Oracle + SQL Server)
        await page.goto('/admin/reports/join-oracle-mssql-productivity');
        await expect(page.locator('.fi-pagination-overview')).toContainText('Tổng số: 10 dòng');
        await expect(page.locator('table.fi-ta-table thead')).toContainText('FULL_NAME');
        await expect(page.locator('table.fi-ta-table thead')).toContainText('total_output');

        // Pattern 4: 3-Way Enterprise Federation (Oracle + SQL Server + Postgres)
        await page.goto('/admin/reports/federated-3way-executive-report');
        await expect(page.locator('.fi-pagination-overview')).toContainText('Tổng số: 2 dòng');
        await expect(page.locator('table.fi-ta-table tbody')).toContainText('NORTH');
        await expect(page.locator('table.fi-ta-table tbody')).toContainText('SOUTH');

        await page.screenshot({
            path: path.join(SCREENSHOTS_DIR, '04-report-runner-federation-3way.png'),
            fullPage: true,
        });

        // Tab 2: Application Information
        await page.locator('button.fi-tabs-item', { hasText: 'Application Information' }).click();
        await expect(page.locator('main')).toContainText('federated-3way-executive-report');
        await expect(page.locator('main')).toContainText('Data Sources Connected');
        await expect(page.locator('main')).toContainText('pg_north');
        await expect(page.locator('main')).toContainText('pg_south');
        await expect(page.locator('main')).toContainText('mssql_erp');
        await expect(page.locator('main')).toContainText('oracle_hr');
        await expect(page.locator('main')).toContainText('Snapshot Health');

        // Tab 3: SQL Query Inspector
        await page.locator('button.fi-tabs-item', { hasText: 'SQL Query' }).click();
        await expect(page.locator('main')).toContainText('Applied Criteria (@Parameters)');
        await expect(page.locator('main')).toContainText('Individual Source Queries');
        await expect(page.locator('main')).toContainText('DuckDB Federation SQL');
        await expect(page.getByRole('button', { name: /Copy SQL/ })).toBeVisible();

        await page.screenshot({
            path: path.join(SCREENSHOTS_DIR, '05-report-runner-tabs-inspector.png'),
            fullPage: true,
        });
    });

    test('E2E 4: Export XLSX, UTF-8 BOM CSV, and chunked ZIP downloads with binary validation', async ({ page }) => {
        await loginAsAdmin(page);

        await page.goto('/admin/reports/pg-multi-plant-contracts');
        await page.locator('input[type="checkbox"][value="pg_south"]').check();
        await page.getByRole('button', { name: 'Tra cứu' }).click();
        await expect(page.locator('.fi-pagination-overview')).toContainText('Tổng số: 45 dòng');

        // 1. Export Excel (.xlsx) and validate with DuckDB read_xlsx()
        const [xlsxDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'Xuất Excel (.xlsx)' }).click(),
        ]);
        expect(xlsxDownload.suggestedFilename()).toMatch(/\.xlsx$/);
        const xlsxPath = path.join(tempDownloadDir, xlsxDownload.suggestedFilename());
        await xlsxDownload.saveAs(xlsxPath);

        const xlsxStat = fs.statSync(xlsxPath);
        expect(xlsxStat.size).toBeGreaterThan(5000);

        const duckOut = execFileSync(
            DUCKDB_BIN,
            ['-dark-mode', '-csv', '-noheader', '-c', `SELECT count(*) FROM read_xlsx('${xlsxPath.replaceAll("'", "''")}');`],
            { encoding: 'utf8', input: '' }
        ).trim();
        expect(Number(duckOut)).toBe(45);

        // 2. Export CSV (.csv) and verify UTF-8 BOM + 45 data rows
        const [csvDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'Xuất CSV (.csv)' }).click(),
        ]);
        expect(csvDownload.suggestedFilename()).toMatch(/\.csv$/);
        const csvPath = path.join(tempDownloadDir, csvDownload.suggestedFilename());
        await csvDownload.saveAs(csvPath);

        const csvBytes = fs.readFileSync(csvPath);
        expect(csvBytes.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf]))).toBe(true);
        const csvLines = csvBytes.toString('utf8').replace(/^\uFEFF/, '').trim().split(/\r?\n/);
        expect(csvLines.length).toBe(46); // 1 header + 45 rows

        // 3. SqlExportPage chunked ZIP export (25 rows / 10 per chunk => 3 chunk files)
        await page.goto('/admin/sql-export');
        await selectFilamentOption(page, 'data_source', 'pg_north');
        await page.keyboard.press('Escape');

        await page.locator('textarea[id*="sql_statement"]').fill(
            'SELECT contract_no, customer_name, total_amount FROM public.v_epcontract ORDER BY contract_no;'
        );

        await page.locator('input[type="radio"][value="csv"]').check();
        const splitToggle = page.locator('button[role="switch"][id*="split_file"], input[type="checkbox"][id*="split_file"]').first();
        await splitToggle.click();

        await selectFilamentOption(page, 'chunk_row_threshold', '10 dòng (Test)');
        await page.keyboard.press('Escape');

        await page.screenshot({
            path: path.join(SCREENSHOTS_DIR, '06-sql-export-page.png'),
            fullPage: true,
        });

        const [zipDownload] = await Promise.all([
            page.waitForEvent('download'),
            page.getByRole('button', { name: 'Thực thi & Tải về' }).click(),
        ]);
        expect(zipDownload.suggestedFilename()).toMatch(/\.zip$/);
        const zipPath = path.join(tempDownloadDir, zipDownload.suggestedFilename());
        await zipDownload.saveAs(zipPath);

        const zipHeader = fs.readFileSync(zipPath).subarray(0, 2).toString('ascii');
        expect(zipHeader).toBe('PK');

        const zipListOut = execFileSync(
            'python3',
            ['-c', 'import sys, zipfile; print("\\n".join(zipfile.ZipFile(sys.argv[1]).namelist()))', zipPath],
            { encoding: 'utf8' }
        ).trim();
        const zipEntries = zipListOut.split(/\r?\n/).filter((e) => e.endsWith('.csv'));
        expect(zipEntries.length).toBeGreaterThanOrEqual(3);
    });
});
