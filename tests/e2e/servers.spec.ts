import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// The cloud provider is the fake (APP_ENV=e2e): no real VPS is created. A fake VPS boots
// on its first status check and the fake probe says Minecraft is up, so a started server
// reaches "Online" on the next poll.
//
// The admin signs in once per test and keeps that page; the player or guest gets a second
// browser context. Fortify allows 5 logins a minute per email, and accounts.spec uses some.

async function createServer(
    page: Page,
    name: string,
    slug: string,
    isPublic: boolean,
): Promise<void> {
    await page.goto('/admin/servers');
    await page.locator('[data-test="new-server"]').click();
    await expect(page).toHaveURL(/\/admin\/servers\/create$/);

    // The provider's list, with the #5276 default (cheapest 8 GB, Germany) picked.
    await expect(page.getByLabel('Location')).toHaveValue('fsn1');
    await expect(page.getByLabel('Server type')).toHaveValue('cx33');

    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Slug').fill(slug);
    if (isPublic) {
        await page.getByLabel(/^Public/).check();
    }
    await page.getByLabel('Java version').selectOption('17');
    await page.locator('[data-test="save-server"]').click();

    await expect(page).toHaveURL(/\/admin\/servers$/);
    await expect(page.getByText(`Created ${name}.`)).toBeVisible();
    await expect(
        page.locator(`[data-test="server-row-${slug}"]`),
    ).toContainText('Stopped');
}

async function stopServer(page: Page, name: string, slug: string) {
    await page.goto('/admin/servers');
    const row = page.locator(`[data-test="server-row-${slug}"]`);
    await expect(row).toContainText('Online');
    await row.getByRole('button', { name: `Stop ${name}` }).click();
    await expect(
        page.getByText(`Stopped ${name}. The VPS is deleted.`),
    ).toBeVisible();
    await expect(row).toContainText('Stopped');
}

test('an admin adds a server, a player starts it and sees it come online', async ({
    page,
    browser,
}) => {
    await logIn(page, 'admin@example.com');
    await page.getByRole('link', { name: 'Servers', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/servers$/);
    await createServer(page, 'Friends Modpack', 'friends-modpack', false);

    // A bad slug is refused with the reason.
    await page.locator('[data-test="new-server"]').click();
    await page.getByLabel('Name').fill('Bad');
    await page.getByLabel('Slug').fill('bad slug!');
    await page.locator('[data-test="save-server"]').click();
    await expect(
        page.getByText('Use lowercase letters, digits and single dashes only'),
    ).toBeVisible();

    const playerContext = await browser.newContext();
    const player = await playerContext.newPage();

    // Private: not on the home page.
    await player.goto('/');
    await expect(
        player.locator('[data-test="no-public-servers"]'),
    ).toBeVisible();

    await logIn(player, 'starter@example.com');
    const card = player.locator('[data-test="server-friends-modpack"]');
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Stopped',
    );
    await card.getByRole('button', { name: 'Start Friends Modpack' }).click();
    await expect(
        player.getByText('Starting Friends Modpack. It takes a few minutes.'),
    ).toBeVisible();

    // The dashboard polls; the fake VPS boots and Minecraft "answers".
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );
    await expect(card.locator('[data-test="server-address"]')).toContainText(
        /203\.0\.113\.\d+/,
    );
    await expect(
        card.getByRole('button', { name: 'Start Friends Modpack' }),
    ).toHaveCount(0);

    // Players can't reach the servers admin.
    const response = await player.request.get('/admin/servers');
    expect(response.status()).toBe(404);
    await playerContext.close();

    // The admin stops it: the VPS is deleted and it can be started again.
    await stopServer(page, 'Friends Modpack', 'friends-modpack');
});

test('a guest starts a public server from the home page', async ({
    page,
    browser,
}) => {
    await logIn(page, 'admin@example.com');
    await createServer(page, 'Open World', 'open-world', true);

    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto('/');
    const card = guest.locator('[data-test="server-open-world"]');
    await expect(card).toBeVisible();
    await card.getByRole('button', { name: 'Start Open World' }).click();
    await expect(
        guest.getByText('Starting Open World. It takes a few minutes.'),
    ).toBeVisible();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );
    await guestContext.close();

    // Stop it again so later specs start under the cap.
    await stopServer(page, 'Open World', 'open-world');
});
