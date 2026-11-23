import { createHmac } from 'node:crypto';
import type { Page } from '@playwright/test';
import { expect, test } from './fixtures';

// Card 5432: the 2FA challenge's code input (vue-input-otp) takes a typed and a pasted
// code. Each seeded user has its own secret and signs in once: Fortify refuses a code
// anyone used in the last minute.

/** E2ESeeder::TOTP_SECRETS. */
const SECRETS = {
    typed: 'JBSWY3DPEHPK3PXP',
    pasted: 'KRSXG5CTMVRXEZLU',
};

function base32(input: string): Buffer {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';

    for (const char of input) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }

    const bytes: number[] = [];

    for (let i = 0; i + 8 <= bits.length; i += 8) {
        bytes.push(parseInt(bits.slice(i, i + 8), 2));
    }

    return Buffer.from(bytes);
}

/** RFC 6238, as Google Authenticator makes it: SHA-1, 30 s, 6 digits. */
function totp(secret: string): string {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const hmac = createHmac('sha1', base32(secret)).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    const value = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return value.toString().padStart(6, '0');
}

async function toChallenge(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
}

test('a typed authentication code signs in', async ({ page }) => {
    await toChallenge(page, 'two-factor-typed@example.com');

    await page.locator('#otp').click();
    await page.keyboard.type(totp(SECRETS.typed), { delay: 50 });
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
});

test('a pasted authentication code signs in', async ({ page }) => {
    await toChallenge(page, 'two-factor-pasted@example.com');

    // The whole code arrives in one input event, as a paste does. A real paste would go
    // through the machine's clipboard, which tests leave alone. (vue-input-otp's own paste
    // handler only runs on iOS; elsewhere the browser's paste is an ordinary input.)
    const code = totp(SECRETS.pasted);
    await page.locator('#otp').click();
    await page.keyboard.insertText(code);
    await expect(page.locator('#otp')).toHaveValue(code);
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
});
