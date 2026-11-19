import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const fallbackChromiumPath = '/home/v25050017/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const customChromiumPath = process.env.PLAYWRIGHT_CHROMIUM_PATH;
const executablePath = customChromiumPath || (fs.existsSync(fallbackChromiumPath) ? fallbackChromiumPath : undefined);

const storageRoot = process.env.SMARTREPORT_E2E_STORAGE ?? fs.mkdtempSync(path.join(os.tmpdir(), 'smartreport-e2e-'));
Object.assign(process.env, {
    SMARTREPORT_E2E_STORAGE: storageRoot,
    APP_ENV: 'testing',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(storageRoot, 'database.sqlite'),
    LARAVEL_STORAGE_PATH: storageRoot,
    APP_CONFIG_CACHE: path.join(storageRoot, 'config.php'),
    SMARTREPORT_SNAPSHOT_DIR: path.join(storageRoot, 'app', 'snapshots'),
    DUCKDB_BINARY: path.resolve('storage/bin/duckdb'),
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    NO_PROXY: `${process.env.NO_PROXY ?? ''},127.0.0.1,localhost`,
    no_proxy: `${process.env.no_proxy ?? ''},127.0.0.1,localhost`,
});
for (const directory of ['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    fs.mkdirSync(path.join(storageRoot, directory), { recursive: true });
}
if (!fs.existsSync(process.env.DB_DATABASE!)) {
    fs.writeFileSync(process.env.DB_DATABASE!, '');
}

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/setup.ts',
    timeout: 120_000,
    expect: {
        timeout: 20_000,
    },
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:18731',
        viewport: { width: 1440, height: 900 },
        acceptDownloads: true,
        trace: 'on-first-retry',
        launchOptions: executablePath ? { executablePath } : undefined,
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=18731 --no-interaction',
        url: 'http://127.0.0.1:18731/admin/login',
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
