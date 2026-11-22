import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.E2E_PORT ?? 8795);
const baseURL = `http://127.0.0.1:${port}`;

// Env beats `.env` in Laravel: a throwaway SQLite file and nothing that reaches a real
// service. tests/e2e/serve.mjs resets the file and seeds it (E2ESeeder).
const appEnv = {
    E2E_PORT: String(port),
    APP_ENV: 'e2e',
    APP_NAME: 'Minecraft VPS Manager',
    APP_DEBUG: 'true',
    APP_URL: baseURL,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: process.env.E2E_DB ?? join(tmpdir(), 'mvm-e2e', 'e2e.sqlite'),
    DB_URL: '',
    CACHE_STORE: 'database',
    QUEUE_CONNECTION: 'sync',
    SESSION_DRIVER: 'file',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'single',
    INERTIA_SSR_ENABLED: 'false',
};

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: 'node tests/e2e/serve.mjs',
        url: `${baseURL}/up`,
        timeout: 120_000,
        reuseExistingServer: false,
        env: appEnv,
    },
});
