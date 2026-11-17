import fs from 'node:fs';
import { defineConfig, devices } from '@playwright/test';

const fallbackChromiumPath = '/home/v25050017/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome';
const customChromiumPath = process.env.PLAYWRIGHT_CHROMIUM_PATH;
const executablePath = customChromiumPath || (fs.existsSync(fallbackChromiumPath) ? fallbackChromiumPath : undefined);

export default defineConfig({
    testDir: './tests/e2e',
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
        baseURL: 'http://127.0.0.1:8000',
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
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/admin/login',
        reuseExistingServer: true,
        timeout: 30_000,
    },
});
