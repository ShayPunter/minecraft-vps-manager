import type { Page } from '@playwright/test';
import { expect, logIn, test } from './fixtures';

// #5321: the Server type list is grouped by the provider's category, sorted cheapest first,
// shows hourly and monthly prices, and leaves out types retired in the chosen location.
// Types and prices come from FakeCloudProvider.

async function optionTexts(page: Page, group: string): Promise<string[]> {
    return page
        .locator(`#server_type optgroup[label="${group}"] option`)
        .allTextContents()
        .then((texts) => texts.map((text) => text.replace(/\s+/g, ' ').trim()));
}

test('the server type list is grouped, priced, skips retired types and flags a retired current type', async ({
    page,
}) => {
    // Its own admin: admin@example.com is near Fortify's 5 logins a minute.
    await logIn(page, 'catalog-admin@example.com');
    await page.goto('/admin/servers/create');

    await expect(page.getByLabel('Location')).toHaveValue('fsn1');
    await expect(page.getByLabel('Server type')).toHaveValue('cx33');

    await expect(page.locator('#server_type optgroup')).toHaveCount(2);
    expect(
        await page
            .locator('#server_type optgroup')
            .evaluateAll((groups) =>
                groups.map((group) => group.getAttribute('label')),
            ),
    ).toEqual(['Shared vCPU', 'Dedicated vCPU']);

    expect(await optionTexts(page, 'Shared vCPU')).toEqual([
        'CX33 · 4 vCPU · 8 GB RAM · 80 GB disk · €0.0104/hour · €6.49/month',
        'CX43 · 8 vCPU · 16 GB RAM · 160 GB disk · €0.0192/hour · €11.99/month',
        'CAX31 · 8 vCPU (Arm) · 16 GB RAM · 160 GB disk · €0.0200/hour · €12.49/month',
    ]);
    expect(await optionTexts(page, 'Dedicated vCPU')).toEqual([
        'CCX23 Dedicated · 4 vCPU · 16 GB RAM · 160 GB disk · €0.0505/hour · €31.49/month',
    ]);

    // Retired everywhere (cpx21) or in Germany (cx53): not offered here.
    await expect(
        page.locator('#server_type option[value="cpx21"]'),
    ).toHaveCount(0);
    await expect(page.locator('#server_type option[value="cx53"]')).toHaveCount(
        0,
    );
    await expect(page.locator('[data-test="type-retired"]')).toHaveCount(0);

    // Helsinki: no dedicated or Arm types, cx53 is still sold there.
    await page.getByLabel('Location').selectOption('hel1');
    await expect(page.locator('#server_type optgroup')).toHaveCount(1);
    expect(
        (await optionTexts(page, 'Shared vCPU')).map(
            (text) => text.split(' ')[0],
        ),
    ).toEqual(['CX33', 'CX43', 'CX53']);

    // A server on a type retired in its location says so on the edit form.
    await page.goto('/admin/servers/create');

    await page.getByLabel('Name').fill('Big Helsinki');
    await page.getByLabel('Slug').fill('big-helsinki');
    await page.getByLabel('Location').selectOption('hel1');
    await page.getByLabel('Server type').selectOption('cx53');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Big Helsinki.')).toBeVisible();

    await page.getByRole('link', { name: 'Edit Big Helsinki' }).click();
    await expect(page.getByLabel('Server type')).toHaveValue('cx53');
    await expect(page.locator('[data-test="type-retired"]')).toHaveCount(0);

    // CX53 is retired in Falkenstein: the form flags it and saving is refused.
    await page.getByLabel('Location').selectOption('fsn1');
    await expect(page.locator('[data-test="type-retired"]')).toContainText(
        "cx53 can't be ordered in this location any more.",
    );
    await expect(page.getByLabel('Server type')).toHaveValue('cx53');
    await page.locator('[data-test="save-server"]').click();
    await expect(
        page.getByText('CX53 is no longer offered in fsn1. Pick another type.'),
    ).toBeVisible();

    await page.getByLabel('Server type').selectOption('cx33');
    await page.locator('[data-test="save-server"]').click();
    await expect(page).toHaveURL(/\/admin\/servers$/);
});
