import { expect, logIn, test } from './fixtures';

// Modpacks (#5352). APP_ENV=e2e: Modrinth is the fake (Cobble Pack AAAAAAAA on Fabric,
// versions 2.0.0 for 26.3 and 1.0.0 for 1.21.11; Forge Frontier BBBBBBBB on NeoForge), so
// nothing calls Modrinth. CX33 is the default type (8 GB): a 6400 MB heap. Own admin.

test('an admin makes a server from a server-pack URL, then switches it to a Modrinth pack', async ({
    page,
}) => {
    await logIn(page, 'modpack-admin@example.com');
    await page.goto('/admin/servers/create');

    await page.getByLabel('Name').fill('All the Mods 10');
    await page.getByLabel('Slug').fill('atm10');
    await page.getByLabel('Software').selectOption('pack_url');

    // A pack URL needs its Java picked; there's no version list or start command.
    await expect(page.getByLabel('Minecraft version')).toHaveCount(0);
    await expect(page.getByLabel('Start command')).toHaveCount(0);
    await expect(page.getByLabel('Java version')).toBeVisible();
    await expect(page.locator('[data-test="pack-hint"]')).toContainText(
        "never the pack's own restart script",
    );

    // http is refused by the browser's own url check first, so check the server's rule
    // with a quote in an https link.
    await page
        .getByLabel('Server pack URL')
        .fill("https://example.com/pack.zip?a='b'");
    await page.locator('[data-test="save-server"]').click();
    await expect(
        page.getByText(
            "Use the https link to the server pack's zip, without spaces or quotes.",
        ),
    ).toBeVisible();

    await page
        .getByLabel('Server pack URL')
        .fill(
            'https://mediafilez.forgecdn.net/files/7012/345/ServerFiles-8.6.zip',
        );
    await page.getByLabel('Java version').selectOption('21');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created All the Mods 10.')).toBeVisible();

    await page.goto('/admin/servers/atm10/edit');
    await expect(page.getByLabel('Software')).toHaveValue('pack_url');
    await expect(page.getByLabel('Server pack URL')).toHaveValue(
        'https://mediafilez.forgecdn.net/files/7012/345/ServerFiles-8.6.zip',
    );
    await expect(page.getByLabel('Java version')).toHaveValue('21');
    await expect(page.locator('[data-test="pack-hint"]')).toContainText(
        'memory from the server type: 6400 MB',
    );

    // Modrinth: search, pick, choose a version.
    await page.getByLabel('Software').selectOption('modrinth');
    await expect(page.getByLabel('Java version')).toHaveCount(0);
    await page.getByLabel('Modrinth modpack').fill('cobble');
    await page.locator('[data-test="modrinth-search"]').click();
    await expect(page.locator('[data-test="modrinth-hits"]')).toContainText(
        'A small Fabric pack for friends.',
    );
    await expect(
        page.getByRole('button', { name: 'Pick Forge Frontier' }),
    ).toHaveCount(0);
    await page.getByRole('button', { name: 'Pick Cobble Pack' }).click();
    await expect(page.locator('[data-test="modrinth-picked"]')).toHaveText(
        'Picked: Cobble Pack',
    );
    await expect(page.getByLabel('Modpack version')).toHaveValue('AAAAAAA2');
    await expect(
        page.getByLabel('Modpack version').locator('option').first(),
    ).toHaveText('2.0.0 · Minecraft 26.3 · Fabric');
    await page.getByLabel('Modpack version').selectOption('AAAAAAA1');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Saved All the Mods 10.')).toBeVisible();

    // After a reload the picker shows the saved pack and version.
    await page.goto('/admin/servers/atm10/edit');
    await expect(page.getByLabel('Software')).toHaveValue('modrinth');
    await expect(page.locator('[data-test="modrinth-picked"]')).toHaveText(
        'Picked: Cobble Pack',
    );
    await expect(page.getByLabel('Modpack version')).toHaveValue('AAAAAAA1');
    await expect(page.getByLabel('Server pack URL')).toHaveCount(0);
});
