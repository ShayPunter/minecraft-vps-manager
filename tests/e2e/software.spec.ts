import { expect, logIn, test } from './fixtures';

// Vanilla / Paper / Fabric servers (#5351). APP_ENV=e2e: the versions come from the fake
// (26.3, 26.2, 1.21.11; 26.x needs Java 25), so nothing calls Mojang or PaperMC. CX33 is
// the default type (8 GB), which gives a 6400 MB heap. Its own admin (Fortify's limit).

test('an admin makes a Paper server, then switches it to Fabric and back to a custom command', async ({
    page,
}) => {
    await logIn(page, 'software-admin@example.com');

    await page.goto('/admin/servers/create');

    // New servers start as Vanilla at the newest release, with no start command to type.
    await expect(page.getByLabel('Software')).toHaveValue('vanilla');
    await expect(page.getByLabel('Minecraft version')).toHaveValue('26.3');
    await expect(
        page.getByLabel('Minecraft version').locator('option').first(),
    ).toHaveText('26.3 (latest)');
    await expect(page.getByLabel('Start command')).toHaveCount(0);
    await expect(page.getByLabel('Java version')).toHaveCount(0);

    await page.getByLabel('Name').fill('Paper Survival');
    await page.getByLabel('Slug').fill('paper-survival');
    await page.getByLabel('Software').selectOption('paper');
    await page.getByLabel('Minecraft version').selectOption('1.21.11');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Paper Survival.')).toBeVisible();

    // The app picked the Java and the memory flags.
    await page.goto('/admin/servers/paper-survival/edit');
    await expect(page.getByLabel('Software')).toHaveValue('paper');
    await expect(page.getByLabel('Minecraft version')).toHaveValue('1.21.11');
    await expect(page.locator('[data-test="install-command"]')).toHaveText(
        'java -Xms6400M -Xmx6400M -jar server.jar nogui',
    );
    await expect(page.locator('[data-test="install-hint"]')).toContainText(
        'The first start installs it',
    );

    // Fabric keeps the version (it lists 1.21.11 too) and runs its own launcher.
    await page.getByLabel('Software').selectOption('fabric');
    await expect(page.getByLabel('Minecraft version')).toHaveValue('1.21.11');
    await expect(page.locator('[data-test="install-command"]')).toHaveCount(0);
    await page.getByLabel('Minecraft version').selectOption('26.3');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Saved Paper Survival.')).toBeVisible();

    await page.goto('/admin/servers/paper-survival/edit');
    await expect(page.getByLabel('Software')).toHaveValue('fabric');
    await expect(page.getByLabel('Minecraft version')).toHaveValue('26.3');
    await expect(page.locator('[data-test="install-command"]')).toHaveText(
        'java -Xms6400M -Xmx6400M -jar fabric-server-launch.jar nogui',
    );

    // "My own start command" shows the fields again, holding what the app wrote.
    await page.getByLabel('Software').selectOption('custom');
    await expect(page.getByLabel('Minecraft version')).toHaveCount(0);
    await expect(page.getByLabel('Start command')).toHaveValue(
        'java -Xms6400M -Xmx6400M -jar fabric-server-launch.jar nogui',
    );
    await expect(page.getByLabel('Java version')).toHaveValue('25');
    await page.getByLabel('Start command').fill('./run.sh');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Saved Paper Survival.')).toBeVisible();

    await page.goto('/admin/servers/paper-survival/edit');
    await expect(page.getByLabel('Software')).toHaveValue('custom');
    await expect(page.getByLabel('Start command')).toHaveValue('./run.sh');
});
