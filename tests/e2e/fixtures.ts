import { expect, test as base } from '@playwright/test';
import type { Page } from '@playwright/test';

/** Every test fails on a console error or an uncaught page error. */
export const test = base.extend<{ consoleErrors: string[] }>({
    consoleErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(msg.text());
                }
            });
            page.on('pageerror', (err) => errors.push(err.message));
            await use(errors);
            expect(errors, 'console errors').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

export async function logIn(
    page: Page,
    email: string,
    password = 'password',
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

export async function logOut(page: Page): Promise<void> {
    await page.context().clearCookies();
}
