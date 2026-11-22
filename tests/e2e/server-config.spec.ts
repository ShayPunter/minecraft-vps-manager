import { expect, logIn, test } from './fixtures';
import { zip } from './zip';

// server.properties, whitelist and pack files from the app (#5377). APP_ENV=e2e: player
// names are looked up by the fake (every valid name exists except ones starting "nobody"),
// world storage is a local folder. Its own admin (Fortify's 5 logins a minute per email).

const RUN_SH = '#!/bin/sh\njava @user_jvm_args.txt -jar server.jar nogui\n';

test('an admin edits a pack server.properties, whitelist and user_jvm_args.txt', async ({
    page,
}) => {
    await logIn(page, 'config-admin@example.com');

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

    await page.goto('/admin/servers/create');
    await page.getByLabel('Name').fill('Pack Config');
    await page.getByLabel('Slug').fill('pack-config');
    await page.getByLabel('Software').selectOption('custom');
    await page.getByLabel('Java version').selectOption('21');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Pack Config.')).toBeVisible();

    // A modpack in a single folder, as most server packs come.
    await page.goto('/admin/servers/pack-config/edit');
    await expect(page.locator('[data-test="config-source"]')).toContainText(
        "Fields you leave empty keep Minecraft's defaults.",
    );
    await page.getByLabel('Upload a world or modpack (.zip)').setInputFiles({
        name: 'pack.zip',
        mimeType: 'application/zip',
        buffer: zip({
            'Pack/server.properties':
                '#Minecraft server properties\nmotd=Pack MOTD\ndifficulty=normal\nwhite-list=false\nlevel-seed=9\n',
            'Pack/run.sh': RUN_SH,
            'Pack/user_jvm_args.txt': '-Xmx4G\n',
            'Pack/world/level.dat': 'level',
        }),
    });
    await page.locator('[data-test="upload-world"]').click();
    await expect(page.getByText(/Uploaded .*-upload\.zip\./)).toBeVisible();

    // The form starts from the pack's own files.
    await page.goto('/admin/servers/pack-config/edit');
    await expect(page.locator('[data-test="config-source"]')).toContainText(
        'Filled from the saved world.',
    );
    await expect(page.getByLabel('MOTD')).toHaveValue('Pack MOTD');
    await expect(page.getByLabel('Difficulty')).toHaveValue('normal');
    await expect(page.locator('[data-test="world-other"]')).toHaveText(
        'level-seed=9',
    );
    await expect(page.getByLabel('run.sh')).toHaveValue(RUN_SH);
    await expect(page.locator('[data-test="run-sh-status"]')).toHaveText(
        "The pack's own run.sh: nothing is written.",
    );

    // The whitelist can't go on with nobody on it.
    await page.getByLabel('MOTD').fill('Friends only');
    await page.getByLabel('Whitelist', { exact: true }).selectOption('true');
    await page.locator('[data-test="save-server"]').click();
    await expect(
        page.getByText(
            'Add at least one player before turning the whitelist on, or nobody can join.',
        ),
    ).toBeVisible();

    await page.getByLabel('Whitelisted players').fill('Steve\nnobody_else');
    await page.locator('[data-test="save-server"]').click();
    await expect(
        page.getByText('No Minecraft account is called nobody_else.'),
    ).toBeVisible();

    await page.getByLabel('Whitelisted players').fill('Steve');

    // Editing a pack file, and putting the pack's back.
    const jvm = page.getByLabel('user_jvm_args.txt');
    await jvm.fill('-Xmx6G');
    await expect(page.locator('[data-test="user-jvm-args-status"]')).toHaveText(
        "Your version is written over the pack's file before every start.",
    );
    await page.locator('[data-test="reset-user-jvm-args"]').click();
    await expect(jvm).toHaveValue('-Xmx4G\n');
    await expect(page.locator('[data-test="user-jvm-args-status"]')).toHaveText(
        "The pack's own file: nothing is written.",
    );
    await jvm.fill('-Xmx6G');

    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Saved Pack Config.')).toBeVisible();

    await page.goto('/admin/servers/pack-config/edit');
    await expect(page.getByLabel('MOTD')).toHaveValue('Friends only');
    await expect(page.getByLabel('Whitelist', { exact: true })).toHaveValue(
        'true',
    );
    await expect(page.getByLabel('Difficulty')).toHaveValue('normal');
    await expect(page.getByLabel('Whitelisted players')).toHaveValue('Steve');
    await expect(jvm).toHaveValue('-Xmx6G\n');
    await expect(page.getByLabel('run.sh')).toHaveValue(RUN_SH);
});
