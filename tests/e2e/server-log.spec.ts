import { expect, logIn, test } from './fixtures';

// Run logs and the EULA box (#5367). APP_ENV=e2e: the VPS is the fake, whose prepare.sh
// answers "prepared", so that is the first line of the run's log. Its own admin (Fortify's
// 5 logins a minute per email). World storage is set by admin-world-storage.spec, which
// runs first; when this spec runs alone it sets it up itself.

test('an admin accepts the EULA, starts a server and reads its log', async ({
    page,
}) => {
    await logIn(page, 'log-admin@example.com');

    await page.goto('/admin/world-storage');
    const storage = page.locator('[data-test="storage-status"]');
    await expect(storage).toBeVisible();
    if ((await storage.textContent())?.includes('Not set up')) {
        await page
            .getByLabel('Host', { exact: true })
            .fill('storage.example.com');
        await page.getByLabel('User').fill('minecraft');
        await page.getByLabel('Password', { exact: true }).fill('storage-pass');
        await page.locator('[data-test="save-storage"]').click();
        await expect(storage).toContainText('Saved here');
    }

    // A new server with the EULA accepted.
    await page.goto('/admin/servers/create');
    await page.getByLabel('Name').fill('Paper Logs');
    await page.getByLabel('Slug').fill('paper-logs');
    await page.getByLabel('Java version').selectOption('25');
    await page
        .getByLabel('I accept the Minecraft EULA for this server')
        .check();
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Paper Logs.')).toBeVisible();

    await page.goto('/admin/servers/paper-logs/edit');
    await expect(
        page.getByLabel('I accept the Minecraft EULA for this server'),
    ).toBeChecked();
    await expect(page.locator('[data-test="eula-hint"]')).toContainText(
        'Accepted by Lee Logs',
    );

    // Never started: the log page says so.
    await page.goto('/admin/servers');
    await page.locator('[data-test="log-paper-logs"]').click();
    await expect(page).toHaveURL(/\/admin\/servers\/paper-logs\/log$/);
    await expect(page.locator('[data-test="no-runs"]')).toBeVisible();

    // Start it; the fake VPS boots and prepare.sh's output lands in the log.
    await page.goto('/dashboard');
    const card = page.locator('[data-test="server-paper-logs"]');
    await card.getByRole('button', { name: 'Start Paper Logs' }).click();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 30_000 },
    );

    await page.goto('/admin/servers/paper-logs/log');
    await expect(page.locator('[data-test="run-status"]')).toContainText(
        'Online',
    );
    await expect(page.locator('[data-test="log-lines"]')).toContainText(
        '[prepare] prepared',
    );
    await expect(page.locator('[data-test="run-picker"] option')).toHaveCount(
        1,
    );

    // The VPS's endpoint: no session or CSRF (a 419 would mean it never reached the
    // controller), and the same 404 for a wrong token as for an unknown run.
    const wrong = await page.request.post('/runs/1/log', {
        headers: {
            Authorization: 'Bearer wrong-token',
            'Content-Type': 'text/plain',
        },
        data: 'hello',
    });
    expect(wrong.status()).toBe(404);
    expect(wrong.headers()['set-cookie']).toBeUndefined();
    const unknown = await page.request.post('/runs/999999/log', {
        headers: {
            Authorization: 'Bearer wrong-token',
            'Content-Type': 'text/plain',
        },
        data: 'hello',
    });
    expect(unknown.status()).toBe(404);

    // Stop it again, so later specs see the usual number of running servers.
    await page.goto('/admin/servers');
    const row = page.locator('[data-test="server-row-paper-logs"]');
    await row.getByRole('button', { name: 'Stop Paper Logs' }).click();
    await expect(row).toContainText('Stopped');

    await page.goto('/admin/servers/paper-logs/log');
    await expect(page.locator('[data-test="run-status"]')).toContainText(
        'Stopped',
    );
    await expect(page.locator('[data-test="log-lines"]')).toContainText(
        '[prepare] prepared',
    );
});
