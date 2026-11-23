import type { Page, Request } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// #5753: slow polls when nothing is moving, a capped live log, layouts in their own chunks.
// One seeded admin per test (Fortify's 5 logins a minute per email).

async function createServer(page: Page, name: string, slug: string) {
    await page.goto('/admin/servers/create');
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Slug').fill(slug);
    await page.getByLabel('Software').selectOption('custom');
    await page.getByLabel('Java version').selectOption('17');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText(`Created ${name}.`)).toBeVisible();
}

/** Starts need world storage (#5288); set it up when this spec runs alone. */
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

const isPoll = (request: Request): boolean =>
    request.headers()['x-inertia-partial-data'] !== undefined;

test('the dashboard polls every 30 s while nothing moves and every 5 s while a server starts', async ({
    page,
}) => {
    await logIn(page, 'perf-polls-admin@example.com');
    await createServer(page, 'Perf Polls', 'perf-polls');

    const polls: Request[] = [];
    let answered = 0;
    page.on('request', (request) => {
        if (isPoll(request)) {
            polls.push(request);
        }
    });
    for (const event of ['requestfinished', 'requestfailed'] as const) {
        page.on(event, (request) => {
            if (isPoll(request)) {
                answered++;
            }
        });
    }
    // n polls so far, each answered and shown, before the clock moves on again.
    const polled = async (n: number): Promise<void> => {
        await expect.poll(() => polls.length).toBe(n);
        await expect.poll(() => answered, { timeout: 15_000 }).toBe(n);
    };

    await page.clock.install();
    await page.goto('/dashboard');
    const card = page.locator('[data-test="server-perf-polls"]');
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Stopped',
    );
    // From here the page's clock only moves when the spec says so.
    const now = await page.evaluate(() => Date.now());
    await page.clock.pauseAt(now + 500);

    // Every server stopped or online: nothing for 25 s, then one poll by 31 s.
    await page.clock.runFor(25_000);
    expect(polls).toHaveLength(0);
    await page.clock.runFor(6_000);
    await polled(1);

    // A server is starting (as another tab's Start would show on the next poll): the
    // poll answers say so, and the dashboard checks every 5 s from then on.
    await page.route('**/dashboard', async (route) => {
        if (!isPoll(route.request())) {
            return route.continue();
        }
        const response = await route.fetch();
        const body = await response.json();
        for (const server of body.props.servers) {
            if (server.slug === 'perf-polls') {
                server.status = 'starting';
                server.status_label = 'Starting';
                server.can_start = false;
            }
        }
        await route.fulfill({ response, json: body });
    });
    await page.clock.runFor(30_000);
    await polled(2);
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Starting',
    );
    await page.clock.runFor(5_500);
    await polled(3);
    await page.clock.runFor(5_000);
    await polled(4);

    // Back to stopped: 30 s again.
    await page.unroute('**/dashboard');
    await page.clock.runFor(5_000);
    await polled(5);
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Stopped',
    );
    await page.clock.runFor(20_000);
    expect(polls).toHaveLength(5);
});

test('the live log keeps the newest 5,000 lines', async ({ page }) => {
    // A start, 6,000 rendered lines and a stop (the world save runs in the request).
    test.setTimeout(120_000);
    await logIn(page, 'perf-log-admin@example.com');
    await ensureWorldStorage(page);
    await createServer(page, 'Perf Log', 'perf-log');

    await page.goto('/dashboard');
    const card = page.locator('[data-test="server-perf-log"]');
    await card.getByRole('button', { name: 'Start Perf Log' }).click();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 30_000 },
    );

    // The VPS sends far more than the view keeps: the next poll brings 6,000 lines.
    let served = false;
    await page.route('**/log/lines**', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        if (!served) {
            served = true;
            body.lines = Array.from({ length: 6000 }, (_, i) => ({
                id: 1_000_000 + i,
                source: 'minecraft',
                line: `flood line ${i + 1}`,
                at: null,
            }));
        }
        await route.fulfill({ response, json: body });
    });

    await page.goto('/admin/servers/perf-log/log');
    const box = page.locator('[data-test="log-lines"]');
    await expect(box).toContainText('[prepare] prepared');
    await expect(page.locator('[data-test="log-trimmed"]')).toHaveText(
        'Showing the newest 5,000 lines.',
        { timeout: 15_000 },
    );
    const rows = box.locator(':scope > div');
    await expect(rows).toHaveCount(5000);
    await expect(rows.first()).toContainText('flood line 1001');
    await expect(rows.last()).toContainText('flood line 6000');
    await expect(box).not.toContainText('[prepare] prepared');
    await page.unroute('**/log/lines**');

    // Stop it again, so later specs see the usual number of running servers.
    await page.goto('/admin/servers');
    const row = page.locator('[data-test="server-row-perf-log"]');
    await row.getByRole('button', { name: 'Stop Perf Log' }).click();
    await expect(row).toContainText('Stopped', { timeout: 30_000 });
});

test('guest pages load no layout chunk and the sidebar stays mounted across visits', async ({
    page,
}) => {
    const scripts: string[] = [];
    page.on('request', (request) => {
        if (request.resourceType() === 'script') {
            scripts.push(new URL(request.url()).pathname);
        }
    });

    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
    expect(scripts.some((path) => /\/AppLayout-[^/]+\.js$/.test(path))).toBe(
        false,
    );

    await logIn(page, 'perf-layout-admin@example.com');
    expect(scripts.some((path) => /\/AuthLayout-[^/]+\.js$/.test(path))).toBe(
        true,
    );

    // Same layout, same element: Inertia keeps the async layout mounted, so the sidebar
    // doesn't flash or remount between dashboard pages.
    const sidebar = page.locator('[data-sidebar="sidebar"]').first();
    await expect(sidebar).toBeVisible();
    await sidebar.evaluate((el) => {
        (el as HTMLElement & { mvmMark?: boolean }).mvmMark = true;
    });
    await sidebar.getByRole('link', { name: 'Servers' }).click();
    await expect(page).toHaveURL(/\/admin\/servers$/);
    await expect(page.getByRole('heading', { name: 'Servers' })).toBeVisible();
    expect(
        await page
            .locator('[data-sidebar="sidebar"]')
            .first()
            .evaluate(
                (el) => (el as HTMLElement & { mvmMark?: boolean }).mvmMark,
            ),
    ).toBe(true);

    await sidebar.getByRole('link', { name: 'Dashboard' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(
        await page
            .locator('[data-sidebar="sidebar"]')
            .first()
            .evaluate(
                (el) => (el as HTMLElement & { mvmMark?: boolean }).mvmMark,
            ),
    ).toBe(true);
});
