import { crc32 } from 'node:zlib';
import { expect, logIn, test } from './fixtures';

// World storage (#5288). APP_ENV=e2e: the "storage server" is a local folder and the
// connection test never opens a connection; the host "unreachable.invalid" fails it.
// The fake VPS's save.sh writes a small zip there (FakeRemoteShell).
// Signs in as its own admin (Fortify's 5 logins a minute per email).
// Named to run first: it checks the "Not set up" state, and servers.spec needs storage set
// (it sets it up itself when run alone).

/** A store-only zip, built by hand so the spec needs no zip package. */
function zip(entries: Record<string, string>): Buffer {
    const locals: Buffer[] = [];
    const centrals: Buffer[] = [];
    let offset = 0;

    for (const [name, text] of Object.entries(entries)) {
        const nameBytes = Buffer.from(name, 'utf8');
        const data = Buffer.from(text, 'utf8');
        const crc = crc32(data);

        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4);
        local.writeUInt32LE(crc, 14);
        local.writeUInt32LE(data.length, 18);
        local.writeUInt32LE(data.length, 22);
        local.writeUInt16LE(nameBytes.length, 26);
        locals.push(local, nameBytes, data);

        const central = Buffer.alloc(46);
        central.writeUInt32LE(0x02014b50, 0);
        central.writeUInt16LE(20, 4);
        central.writeUInt16LE(20, 6);
        central.writeUInt32LE(crc, 16);
        central.writeUInt32LE(data.length, 20);
        central.writeUInt32LE(data.length, 24);
        central.writeUInt16LE(nameBytes.length, 28);
        central.writeUInt32LE(offset, 42);
        centrals.push(central, nameBytes);

        offset += local.length + nameBytes.length + data.length;
    }

    const directory = Buffer.concat(centrals);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(Object.keys(entries).length, 8);
    end.writeUInt16LE(Object.keys(entries).length, 10);
    end.writeUInt32LE(directory.length, 12);
    end.writeUInt32LE(offset, 16);

    return Buffer.concat([...locals, directory, end]);
}

test('an admin sets up world storage, uploads a world and downloads it again', async ({
    page,
}) => {
    await logIn(page, 'storage-admin@example.com');
    await page
        .getByRole('link', { name: 'World storage', exact: true })
        .click();
    await expect(page).toHaveURL(/\/admin\/world-storage$/);

    const status = page.locator('[data-test="storage-status"]');
    await expect(status).toContainText('Not set up');
    await expect(page.locator('[data-test="storage-problem"]')).toContainText(
        "World storage isn't set up yet",
    );

    // Port follows the type while it's a default.
    await expect(page.getByLabel('Port')).toHaveValue('22');
    await page.getByLabel('Type').selectOption('ftp');
    await expect(page.getByLabel('Port')).toHaveValue('21');
    await expect(page.getByLabel('Use TLS (FTPS)')).toBeChecked();
    await page.getByLabel('Use TLS (FTPS)').uncheck();
    await expect(page.locator('[data-test="plain-ftp-warning"]')).toBeVisible();
    await page.getByLabel('Type').selectOption('sftp');
    await expect(page.getByLabel('Port')).toHaveValue('22');

    // A failed test says why and saves nothing.
    await page.getByLabel('Host', { exact: true }).fill('unreachable.invalid');
    await page.getByLabel('Port').fill('23');
    await page.getByLabel('User').fill('u123456-sub1');
    await page.getByLabel('Password', { exact: true }).fill('storage-pass-123');
    await page.locator('[data-test="test-storage"]').click();
    await expect(
        page.getByText("Couldn't connect to unreachable.invalid:23"),
    ).toBeVisible();
    await expect(status).toContainText('Not set up');

    // A refused sign-in says so, with what to check on a Storage Box (#5348).
    await page
        .getByLabel('Host', { exact: true })
        .fill('u123456.your-storagebox.de');
    await page.getByLabel('Port').fill('22');
    await page.getByLabel('Password', { exact: true }).fill('wrong-password');
    await page.locator('[data-test="save-storage"]').click();
    await expect(
        page.getByText(
            'Not saved. Reached u123456.your-storagebox.de:22, but it refused to sign in user "u123456-sub1" with that password.',
        ),
    ).toBeVisible();
    await expect(
        page.getByText('"External reachability" must be on'),
    ).toBeVisible();
    await expect(status).toContainText('Not set up');
    await page.getByLabel('Port').fill('23');

    // Test and save pins the host key.
    await page
        .getByLabel('Host', { exact: true })
        .fill('u123456.your-storagebox.de');
    await page.getByLabel('Password', { exact: true }).fill('storage-pass-123');
    await page.locator('[data-test="save-storage"]').click();
    await expect(
        page.getByText('Saved. Connected, and the base folder is writable.'),
    ).toBeVisible();
    await expect(status).toContainText('Saved here');
    await expect(status).toContainText(
        'Ready: starts and stops use these settings.',
    );
    await expect(page.locator('[data-test="host-key"]')).toHaveText(/^SHA256:/);

    // The password never comes back to the browser.
    await page.reload();
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute(
        'placeholder',
        'Saved: leave empty to keep it',
    );
    expect(await page.content()).not.toContain('storage-pass-123');

    // A server to give a world to.
    await page.goto('/admin/servers/create');
    await page.getByLabel('Name').fill('Storage Test');
    await page.getByLabel('Slug').fill('storage-test');
    await page.getByLabel('Java version').selectOption('21');
    await page.locator('[data-test="save-server"]').click();
    await expect(page.getByText('Created Storage Test.')).toBeVisible();
    await page.getByRole('link', { name: 'Edit Storage Test' }).click();
    await expect(page.locator('[data-test="no-archives"]')).toBeVisible();

    // A zip that would unpack outside the server folder is refused.
    await page.getByLabel('Upload a world or modpack (.zip)').setInputFiles({
        name: 'evil.zip',
        mimeType: 'application/zip',
        buffer: zip({ 'ok.txt': 'fine', '../../etc/cron.d/x': 'bad' }),
    });
    await page.locator('[data-test="upload-world"]').click();
    await expect(
        page.getByText(
            'The zip has an entry that would unpack outside the server folder',
        ),
    ).toBeVisible();
    await expect(page.locator('[data-test="no-archives"]')).toBeVisible();

    // A good one becomes the current world.
    const world = zip({
        'server.properties': 'motd=Storage Test',
        'world/level.dat': 'pretend level data',
    });
    await page.getByLabel('Upload a world or modpack (.zip)').setInputFiles({
        name: 'world.zip',
        mimeType: 'application/zip',
        buffer: world,
    });
    await page.locator('[data-test="upload-world"]').click();
    await expect(
        page.getByText(
            /Uploaded .*-upload\.zip\. Storage Test starts with it next time\./,
        ),
    ).toBeVisible();
    const archives = page.locator('[data-test="archives"] li');
    await expect(archives).toHaveCount(1);
    await expect(archives.first()).toContainText('current');
    await expect(archives.first()).toContainText('uploaded by Sam Storage');

    // Download streams it back byte for byte.
    const href = await archives
        .first()
        .getByRole('link', { name: /^Download / })
        .getAttribute('href');
    expect(href).toMatch(/\/admin\/servers\/storage-test\/world\/\d+$/);
    const download = await page.request.get(href!);
    expect(download.status()).toBe(200);
    expect(download.headers()['content-disposition']).toContain('-upload.zip');
    expect(Buffer.compare(await download.body(), world)).toBe(0);

    // A start puts that world on the VPS; the stop saves a new archive before the VPS goes.
    await page.goto('/dashboard');
    const card = page.locator('[data-test="server-storage-test"]');
    await card.getByRole('button', { name: 'Start Storage Test' }).click();
    await expect(card.locator('[data-test="server-status"]')).toHaveText(
        'Online',
        { timeout: 20_000 },
    );
    await card.getByRole('button', { name: 'Stop Storage Test' }).click();
    await expect(
        page.getByText(
            'Stopped Storage Test. The world is saved and the VPS is deleted.',
        ),
    ).toBeVisible();

    await page.goto('/admin/servers');
    await page.getByRole('link', { name: 'Edit Storage Test' }).click();
    await expect(archives).toHaveCount(2);
    await expect(archives.first()).toContainText('saved on stop');
    await expect(archives.first()).toContainText('current');
    await expect(archives.nth(1)).toContainText('uploaded by Sam Storage');
    await expect(archives.nth(1)).not.toContainText('current');
    const savedHref = await archives
        .first()
        .getByRole('link', { name: /^Download / })
        .getAttribute('href');
    const saved = await page.request.get(savedHref!);
    expect(saved.status()).toBe(200);
    expect(saved.headers()['content-disposition']).toContain('-save.zip');
    expect((await saved.body()).subarray(0, 2).toString()).toBe('PK');

    // Delete the server and its worlds.
    await page.goto('/admin/servers');
    await page.getByRole('button', { name: 'Delete Storage Test' }).click();
    await page.getByLabel(/Also delete its saved worlds from storage/).check();
    await page.locator('[data-test="confirm-delete-server"]').click();
    await expect(
        page.getByText('Deleted Storage Test and its saved worlds.'),
    ).toBeVisible();
    expect((await page.request.get(href!)).status()).toBe(404);
});
