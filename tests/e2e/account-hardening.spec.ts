import { expect, logIn, logOut, test } from './fixtures';

// Card 5532: a new email address on the profile page needs the current
// password. Signs in as its own seeded player (Fortify's login limit).

test('changing the email address asks for the current password', async ({
    page,
}) => {
    await logIn(page, 'hardening@example.com');

    await page.goto('/settings/profile');
    const email = page.getByRole('textbox', { name: 'Email address' });
    const current = page.getByLabel('Current password');
    await expect(email).toHaveValue('hardening@example.com');
    await expect(current).toHaveCount(0);

    await email.fill('hardening-moved@example.com');
    await expect(current).toBeVisible();
    await current.fill('not-my-password');
    await page.locator('[data-test="update-profile-button"]').click();
    await expect(page.getByText('The password is incorrect.')).toBeVisible();
    await page.reload();
    await expect(email).toHaveValue('hardening@example.com');

    await email.fill('hardening-moved@example.com');
    await current.fill('password');
    await page.locator('[data-test="update-profile-button"]').click();
    await expect(page.getByText('Profile updated.')).toBeVisible();
    await expect(current).toHaveCount(0);

    await logOut(page);
    await logIn(page, 'hardening-moved@example.com');
});
