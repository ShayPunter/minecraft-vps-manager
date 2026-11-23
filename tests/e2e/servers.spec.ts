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
    visibility: 'public' | 'private' | 'link',
): Promise<void> {
    await page.goto('/admin/servers');
    await page.locator('[data-test="new-server"]').click();
    await expect(page).toHaveURL(/\/admin\/servers\/create$/);

    // The provider's list, with the #5276 default (cheapest 8 GB, Germany) picked.
    await expect(page.getByLabel('Location')).toHaveValue('fsn1');
    await expect(page.getByLabel('Server type')).toHaveValue('cx33');

    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Slug').fill(slug);
    // Private is the default (#5462).
    await expect(page.getByLabel('Visibility')).toHaveValue('private');
    if (visibility !== 'private') {
        await page.getByLabel('Visibility').selectOption(visibility);
    }
    await page.getByLabel('Software').selectOption('custom');
    await page.getByLabel('Java version').selectOption('17');
    await page.locator('[data-test="save-server"]').click();

    await expect(page).toHaveURL(/\/admin\/servers$/);
    await expect(page.getByText(`Created ${name}.`)).toBeVisible();
    await expect(
        page.locator(`[data-test="server-row-${slug}"]`),
    ).toContainText('Stopped');
}

/**
 * Starts need world storage (#5288). admin-world-storage.spec runs first and saves it; when
 * this spec runs alone, set it up here.
 */
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

async function stopServer(page: Page, name: string, slug: string) {
    await page.goto('/admin/servers');
    const row = page.locator(`[data-test="server-row-${slug}"]`);
    await expect(row).toContainText('Online');
    await row.getByRole('button', { name: `Stop ${name}` }).click();
    await expect(
        page.getByText(
            `Stopped ${name}. The world is saved and the VPS is deleted.`,
        ),
    ).toBeVisible();
    await expect(row).toContainText('Stopped');
}

test('an admin adds a server, a player starts it and sees it come online', async ({
    page,
    browser,
}) => {
    await logIn(page, 'admin@example.com');
    await ensureWorldStorage(page);
    await page.getByRole('link', { name: 'Servers', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/servers$/);
    await createServer(page, 'Friends Modpack', 'friends-modpack', 'private');

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
    // Copy copies exactly the address shown (#5450). The clipboard is recorded in the page,
    // not the OS one (shared with other specs and Shay's desktop).
    await player.evaluate(() => {
        const w = window as unknown as { copied: string[] };
        w.copied = [];
        navigator.clipboard.writeText = async (text: string) => {
            w.copied.push(text);
        };
    });
    const copyButton = card.getByRole('button', {
        name: 'Copy the address of Friends Modpack',
    });
    await copyButton.click();
    await expect(copyButton).toContainText('Copied');
    const shown = await card
        .locator('[data-test="server-address"] code')
        .textContent();
    expect(
        await player.evaluate(
            () => (window as unknown as { copied: string[] }).copied,
        ),
    ).toEqual([shown?.trim()]);
    await player.evaluate(() => {
        navigator.clipboard.writeText = async () => {
            throw new Error('Denied');
        };
    });
    await copyButton.click();
    await expect(copyButton).toContainText("Couldn't copy");
    await expect(
        card.getByRole('button', { name: 'Start Friends Modpack' }),
    ).toHaveCount(0);
    // Nobody on yet, so it counts down to the watchdog's idle stop (#5378).
    await expect(card.locator('[data-test="server-players"]')).toHaveText(
        /^(Checking who's on… )?Stops in 1[45] min if nobody joins\.$/,
    );
    // The player started it, so they may stop it.
    await expect(
        card.getByRole('button', { name: 'Stop Friends Modpack' }),
    ).toBeVisible();

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
    await ensureWorldStorage(page);
    await createServer(page, 'Open World', 'open-world', 'public');

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
    // Guests can start a public server, but only admins can stop it.
    await expect(
        card.getByRole('button', { name: 'Stop Open World' }),
    ).toHaveCount(0);
    // The home page counts down to the idle stop too (#5378).
    await expect(card.locator('[data-test="server-players"]')).toHaveText(
        /Stops in 1[45] min if nobody joins\.$/,
    );
    await guestContext.close();

    // And Admin → Servers.
    await page.goto('/admin/servers');
    await expect(
        page.locator(
            '[data-test="server-row-open-world"] [data-test="server-idle"]',
        ),
    ).toHaveText(/Stops in 1[45] min if nobody joins\.$/);

    // Its edit page counts that start against the guests' daily budget (#5526).
    await page.getByRole('link', { name: 'Edit Open World' }).click();
    await expect(page.locator('[data-test="guest-starts"]')).toHaveText(
        /^Guests who aren't signed in can start it \d+ times a day \(1 today\), and only while 1 of the \d+ server slots stay free for signed-in players\./,
    );
    await page.goto('/admin/servers');

    // Stop it again so later specs start under the cap.
    await stopServer(page, 'Open World', 'open-world');
});

test('a link-only server opens and starts only through its share link', async ({
    page,
    browser,
}) => {
    // #5462. Not "Name"/"Slug" in the server's name: other specs' label lookups. Its own
    // admin: the other tests sign admin@example.com in within the same minute (Fortify: 5).
    await logIn(page, 'share-admin@example.com');
    await ensureWorldStorage(page);
    await createServer(page, 'Community Realm', 'community-realm', 'link');
    await expect(
        page.locator('[data-test="server-row-community-realm"]'),
    ).toContainText('Link only');

    // The edit page shows the link and copies exactly that.
    await page.getByRole('link', { name: 'Edit Community Realm' }).click();
    await expect(page.getByLabel('Visibility')).toHaveValue('link');
    const shareUrl = (
        await page.locator('[data-test="share-url"]').textContent()
    )?.trim();
    expect(shareUrl).toMatch(/\/s\/[A-Za-z0-9]{40}$/);
    await page.evaluate(() => {
        const w = window as unknown as { copied: string[] };
        w.copied = [];
        navigator.clipboard.writeText = async (text: string) => {
            w.copied.push(text);
        };
    });
    const copy = page.getByRole('button', {
        name: 'Copy the share link of Community Realm',
    });
    await copy.click();
    await expect(copy).toContainText('Copied');
    expect(
        await page.evaluate(
            () => (window as unknown as { copied: string[] }).copied,
        ),
    ).toEqual([shareUrl]);

    // Not on the home page, nor on a player's dashboard.
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto('/');
    await expect(
        guest.locator('[data-test="server-community-realm"]'),
    ).toHaveCount(0);
    // (The slug start route's 404 for it is in SharedServerTest: a bare POST here is a 419.)

    // A guest with the link sees that server alone and starts it, no account needed.
    await guest.goto(shareUrl!);
    await expect(
        guest.getByRole('heading', { name: 'Community Realm' }),
    ).toBeVisible();
    const card = guest.locator('[data-test="server-community-realm"]');
    await card.getByRole('button', { name: 'Start Community Realm' }).click();
    await expect(
        guest.getByText('Starting Community Realm. It takes a few minutes.'),
    ).toBeVisible();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );
    await expect(card.locator('[data-test="server-address"]')).toBeVisible();
    await expect(
        card.getByRole('button', { name: 'Stop Community Realm' }),
    ).toHaveCount(0);

    // The admin stops it, then makes a new link: the old one stops working at once.
    await stopServer(page, 'Community Realm', 'community-realm');
    await page.getByRole('link', { name: 'Edit Community Realm' }).click();
    await page.locator('[data-test="new-share-link"]').click();
    await expect(
        page.getByText('New link made. The old one no longer works.'),
    ).toBeVisible();
    const newUrl = (
        await page.locator('[data-test="share-url"]').textContent()
    )?.trim();
    expect(newUrl).toMatch(/\/s\/[A-Za-z0-9]{40}$/);
    expect(newUrl).not.toBe(shareUrl);
    expect((await guest.request.get(shareUrl!)).status()).toBe(404);
    expect((await guest.request.get(newUrl!)).status()).toBe(200);
    await guestContext.close();
});

test('the player who started a server stops it from the dashboard', async ({
    page,
    browser,
}) => {
    await logIn(page, 'admin@example.com');
    await ensureWorldStorage(page);
    await createServer(page, 'Weekend Survival', 'weekend-survival', 'private');

    // The idle setting is on the form, with the default.
    await page.goto('/admin/servers');
    await page.getByRole('link', { name: 'Edit Weekend Survival' }).click();
    await expect(
        page.getByLabel('Stop after (minutes with nobody on)'),
    ).toHaveValue('15');

    const playerContext = await browser.newContext();
    const player = await playerContext.newPage();
    await logIn(player, 'starter@example.com');
    const card = player.locator('[data-test="server-weekend-survival"]');
    await card.getByRole('button', { name: 'Start Weekend Survival' }).click();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );

    await card.getByRole('button', { name: 'Stop Weekend Survival' }).click();
    await expect(
        player.getByText(
            'Stopped Weekend Survival. The world is saved and the VPS is deleted.',
        ),
    ).toBeVisible();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Stopped',
    );
    await expect(
        card.getByRole('button', { name: 'Start Weekend Survival' }),
    ).toBeVisible();
    // It came online once, so the next start has an estimate (#5379). The fake VPS
    // boots in seconds, which rounds up to a minute.
    await expect(card.locator('[data-test="server-boot-estimate"]')).toHaveText(
        'Usually ready in about 1 min.',
    );
    await playerContext.close();
});
