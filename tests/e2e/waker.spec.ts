import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// Wake on join (#5620): the waker's address in Admin → DNS, "Wake on join" on a server,
// and its name pointing at the waker while it's stopped. Cloudflare is the fake: records
// live in the app's cache. The waker itself (servers:waker) is covered by PHPUnit over
// real sockets; nothing here listens on 25565.

const ZONE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/** Starts need world storage (#5288): set it up when this spec runs alone. */
async function ensureWorldStorage(page: Page): Promise<void> {
    await page.goto('/admin/world-storage');
    const status = page.locator('[data-test="storage-status"]');
    await expect(status).toBeVisible();
    if (!(await status.textContent())?.includes('Not set up')) {
        return;
    }
    await page.getByLabel('Host', { exact: true }).fill('storage.example.com');
    await page.getByLabel('User').fill('minecraft');
    await page.getByLabel('Password', { exact: true }).fill('storage-pass');
    await page.locator('[data-test="save-storage"]').click();
    await expect(status).toContainText('Saved here');
}

test('a stopped server that wakes on join has its name at the waker', async ({
    page,
}) => {
    await logIn(page, 'waker-admin@example.com');
    await ensureWorldStorage(page);

    await page.goto('/admin/dns');
    await page.getByLabel('Domain').fill('example.com');
    await page.getByLabel('Zone ID').fill(ZONE_ID);
    await page.getByLabel('API token').fill('cf-e2e-token');

    // A private address can't be reached by players.
    const waker = page.getByLabel('Waker address (optional)');
    await waker.fill('192.168.1.20');
    await page.locator('[data-test="save-dns"]').click();
    await expect(
        page.getByText('Use the public address players can reach'),
    ).toBeVisible();

    await page.getByLabel('API token').fill('cf-e2e-token');
    await waker.fill('198.51.100.10');
    await page.locator('[data-test="save-dns"]').click();
    await expect(
        page.getByText("Saved. The token can edit example.com's DNS."),
    ).toBeVisible();
    await page.reload();
    await expect(waker).toHaveValue('198.51.100.10');
    await expect(page.locator('[data-test="waker-hint"]')).toContainText(
        'php artisan servers:waker',
    );

    // A server that wakes on join.
    await page.goto('/admin/servers/create');
    await page.getByLabel('Name').fill('Night Owl');
    await page.getByLabel('Slug').fill('night-owl');
    await page.getByLabel('Software').selectOption('custom');
    await page.getByLabel('Java version').selectOption('21');
    await page.getByLabel('Subdomain (optional)').fill('owl');
    await page.getByLabel('Wake on join').check();
    await expect(page.locator('[data-test="wake-on-join-hint"]')).toContainText(
        'joining starts it',
    );
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Night Owl.')).toBeVisible();

    const records = page.locator('[data-test="dns-records"]');
    await page.goto('/admin/dns');
    await expect(records).toContainText(
        'owl.example.com → 198.51.100.10 (the waker)',
    );

    // Started, the name moves to the VPS; stopped, it's back at the waker.
    await page.goto('/dashboard');
    const card = page.locator('[data-test="server-night-owl"]');
    await card.getByRole('button', { name: 'Start Night Owl' }).click();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );
    await expect(card.locator('[data-test="server-address"]')).toContainText(
        'owl.example.com',
    );

    await page.goto('/admin/dns');
    await expect(records).toContainText(/owl\.example\.com → 203\.0\.113\.\d+/);
    await expect(records).not.toContainText('(the waker)');

    await page.goto('/admin/servers');
    const row = page.locator('[data-test="server-row-night-owl"]');
    await row.getByRole('button', { name: 'Stop Night Owl' }).click();
    await expect(
        page.getByText(
            'Stopped Night Owl. The world is saved and the VPS is deleted.',
        ),
    ).toBeVisible();

    await page.goto('/admin/dns');
    await expect(records).toContainText(
        'owl.example.com → 198.51.100.10 (the waker)',
    );

    // Turning it off takes the name off the waker.
    await page.goto('/admin/servers/night-owl/edit');
    await expect(page.getByLabel('Wake on join')).toBeChecked();
    await page.getByLabel('Wake on join').uncheck();
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Saved Night Owl.')).toBeVisible();
    await page.goto('/admin/dns');
    await expect(page.locator('[data-test="dns-records"]')).toHaveCount(0);
});
