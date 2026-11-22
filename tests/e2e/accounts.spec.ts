import { expect, logIn, logOut, test } from './fixtures';

test('the welcome page has no sign-up and lists public servers', async ({
    page,
}) => {
    await page.goto('/');
    await expect(
        page.getByRole('heading', {
            name: 'Minecraft servers, on when you play',
        }),
    ).toBeVisible();
    await expect(page.locator('[data-test="no-public-servers"]')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Log in' })).toBeVisible();
    await expect(
        page.getByRole('link', { name: /register|sign up/i }),
    ).toHaveCount(0);

    await page.goto('/login');
    await expect(page.getByRole('link', { name: /sign up/i })).toHaveCount(0);
});

test('an admin creates a player, who signs in and is kept out of admin pages', async ({
    page,
}) => {
    await logIn(page, 'admin@example.com');

    await page.getByRole('link', { name: 'Users', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(
        page.locator('[data-test="user-row-player@example.com"]'),
    ).toBeVisible();

    await page.locator('[data-test="new-user"]').click();
    await expect(page).toHaveURL(/\/admin\/users\/create$/);
    await page.getByLabel('Name').fill('Sam Friend');
    await page.getByLabel('Email address').fill('sam@example.com');
    await page.getByLabel('Role').selectOption('player');
    await page.getByLabel('Password', { exact: true }).fill('sam-password-1');
    await page.getByLabel('Confirm password').fill('sam-password-1');
    await page.locator('[data-test="save-user"]').click();

    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(page.getByText('Created Sam Friend.')).toBeVisible();
    const row = page.locator('[data-test="user-row-sam@example.com"]');
    await expect(row).toContainText('Player');

    // Edit: rename, password left blank so it stays the same.
    await row.getByRole('link', { name: 'Edit Sam Friend' }).click();
    await expect(page.getByLabel('Role')).toHaveValue('player');
    await page.getByLabel('Name').fill('Sam Pal');
    await page.locator('[data-test="save-user"]').click();
    await expect(page.getByText('Saved Sam Pal.')).toBeVisible();
    await expect(
        page.locator('[data-test="user-row-sam@example.com"]'),
    ).toContainText('Sam Pal');

    // The admin's own row has no delete button.
    await expect(
        page
            .locator('[data-test="user-row-admin@example.com"]')
            .getByRole('button', { name: /^Delete/ }),
    ).toHaveCount(0);

    await logOut(page);
    await logIn(page, 'sam@example.com', 'sam-password-1');
    await expect(
        page.getByRole('heading', { name: 'Hi Sam Pal' }),
    ).toBeVisible();
    await expect(page.locator('[data-test="no-servers"]')).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Users', exact: true }),
    ).toHaveCount(0);

    // Admin pages answer 404 to players. Check them with requests, so the browser
    // doesn't log the 404 as a console error.
    for (const url of [
        '/admin',
        '/admin/users',
        '/admin/users/create',
        '/admin/users/1/edit',
    ]) {
        const response = await page.request.get(url, { maxRedirects: 0 });
        expect(response.status(), url).toBe(404);
    }
});

test('an admin deletes a player after confirming', async ({ page }) => {
    await logIn(page, 'admin@example.com');
    await page.goto('/admin/users');

    const row = page.locator('[data-test="user-row-player@example.com"]');
    await row.getByRole('button', { name: 'Delete Pat Player' }).click();
    const dialog = page.getByRole('dialog');
    await expect(
        dialog.getByRole('heading', { name: 'Delete Pat Player?' }),
    ).toBeVisible();
    await dialog.locator('[data-test="confirm-delete-user"]').click();

    await expect(page.getByText('Deleted Pat Player.')).toBeVisible();
    await expect(row).toHaveCount(0);
});

test('/version answers JSON', async ({ request }) => {
    const response = await request.get('/version');
    expect(response.ok()).toBe(true);
    expect(Object.keys(await response.json())).toEqual([
        'version',
        'commit',
        'deployed_at',
    ]);
});
