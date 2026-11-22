import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// Admin → DNS (#5338). Cloudflare is the fake (APP_ENV=e2e): records live in the app's
// cache and nothing is called. Zone eeee… belongs to "other.example" in the fake, so a
// wrong zone id can be shown.

const ZONE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const OTHER_ZONE_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

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

test('an admin sets up DNS, gives a server a name and players see the name while it runs', async ({
    page,
}) => {
    await logIn(page, 'dns-admin@example.com');
    await ensureWorldStorage(page);

    await page.getByRole('link', { name: 'DNS', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/dns$/);
    const status = page.locator('[data-test="dns-status"]');
    await expect(page.locator('[data-test="dns-problem"]')).toContainText(
        "DNS isn't set up",
    );

    // A zone id that belongs to another domain is refused, and nothing is saved.
    await page.getByLabel('Domain').fill('example.com');
    await page.getByLabel('Zone ID').fill(OTHER_ZONE_ID);
    await page.getByLabel('API token').fill('cf-e2e-token');
    await page.locator('[data-test="save-dns"]').click();
    await expect(page.locator('[data-test="dns-error"]')).toContainText(
        "Not saved. That zone id doesn't belong to example.com",
    );

    await page.getByLabel('Zone ID').fill(ZONE_ID);
    await page.getByLabel('API token').fill('cf-e2e-token');
    await page.locator('[data-test="save-dns"]').click();
    await expect(
        page.getByText("Saved. The token can edit example.com's DNS."),
    ).toBeVisible();
    await expect(status).toContainText('Ready: servers with a subdomain');
    await expect(page.locator('[data-test="dns-no-records"]')).toBeVisible();

    // The token never comes back to the browser.
    await page.reload();
    await expect(page.getByLabel('API token')).toHaveValue('');
    await expect(page.getByLabel('API token')).toHaveAttribute(
        'placeholder',
        'Saved: leave empty to keep it',
    );
    expect(await page.content()).not.toContain('cf-e2e-token');

    // A server with a name.
    await page.goto('/admin/servers/create');
    await page.getByLabel('Name').fill('Lobby Server');
    await page.getByLabel('Slug').fill('lobby-server');
    await page.getByLabel('Java version').selectOption('21');
    await page.getByLabel('Subdomain (optional)').fill('Play.Lobby');
    await expect(page.locator('[data-test="subdomain-hint"]')).toContainText(
        'Players join with play.lobby.example.com while it runs.',
    );
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Lobby Server.')).toBeVisible();

    await page.goto('/admin/dns');
    await expect(page.locator('[data-test="dns-servers"]')).toContainText(
        'Lobby Server: play.lobby.example.com',
    );

    // Start it: once online, the card shows the name, and the record points at the VPS.
    await page.goto('/dashboard');
    const card = page.locator('[data-test="server-lobby-server"]');
    await card.getByRole('button', { name: 'Start Lobby Server' }).click();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );
    await expect(card.locator('[data-test="server-address"]')).toContainText(
        'play.lobby.example.com',
    );
    await expect(card.locator('[data-test="server-dns-error"]')).toHaveCount(0);

    await page.goto('/admin/dns');
    await expect(page.locator('[data-test="dns-records"]')).toContainText(
        /play.lobby.example\.com → 203\.0\.113\.\d+/,
    );

    // Stopping deletes the record.
    await page.goto('/admin/servers');
    const row = page.locator('[data-test="server-row-lobby-server"]');
    await expect(row).toContainText('play.lobby.example.com');
    await row.getByRole('button', { name: 'Stop Lobby Server' }).click();
    await expect(
        page.getByText(
            'Stopped Lobby Server. The world is saved and the VPS is deleted.',
        ),
    ).toBeVisible();

    await page.goto('/admin/dns');
    await expect(page.locator('[data-test="dns-no-records"]')).toBeVisible();
});
