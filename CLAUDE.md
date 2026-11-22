# Minecraft VPS Manager

A web app that lets friends boot a Minecraft server on a cloud VPS on demand. A watchdog
pings the server every minute and, after 15 minutes with no players, stops it and deletes
the VPS so it only costs money while people play. Shay ran it in 2022 on Linode; it's being
revived (Nov 2026) on **Hetzner Cloud** and **Laravel 13**, hosted on **Forge**.

Repo: `ShayPunter/minecraft-vps-manager` (public, Shay's personal account: don't transfer).
GroundStation tag: `minecraft vps manager`. Tracker card #5286. See the control repo's
`CLAUDE.md` for the board, feature workflow and Laravel/Forge notes, which all apply here.

## Stack

Laravel 13 Vue starter kit (#5280): Inertia 3, Vue 3, TypeScript, Fortify (login, password
reset, 2FA, password confirmation; **no registration, no email verification**), Wayfinder,
Vite+ (`vp`), Tailwind 4, shadcn-vue components in `resources/js/components/ui`. Tests are
PHPUnit 12 (the kit's choice, not Pest). The 2022/2023 Laravel 10 / Jetstream / Linode code
was replaced wholesale; it's in git history before #5280 if anyone needs to look.

## Commands

- Install: `composer install`, `npm ci`.
- Dev: `composer run dev` (artisan serve + Vite).
- First admin: `php artisan app:create-admin` (prompts; `--name --email --password` for
  scripts). An existing email (any case; emails are trimmed and lowercased) is promoted to
  admin instead. Forge's Commands panel has no terminal, so prompts can't run there: the
  command then needs `--email` (and `--name` for a new account), and generates and prints a
  password when `--password` is left out (#5299). Every failure ends "Nothing was changed."
  with exit 1. `php artisan app:users` lists id, email and role to check the result.
- Checks, same as CI (`.github/workflows/ci.yml`, one job named **Tests**, the required
  check on `main`): `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`
  (the default 128M crashes a worker), `npm run build` (generates Wayfinder routes in
  `resources/js/routes` and `resources/js/actions`, gitignored), `npm run check`,
  `npm run types:check`, `php artisan test`, `npm run test:e2e`.
- Feature tests render the Vite manifest: build before `php artisan test`.
- `laravel/pao` shortens PHPUnit/PHPStan output for agents; `PAO_DISABLE=1` for the full report.

## E2E

Playwright (`playwright.config.ts`, specs in `tests/e2e`). The web server is
`tests/e2e/serve.mjs`: it resets a throwaway SQLite file (`%TEMP%/mvm-e2e/e2e.sqlite`,
override with `E2E_DB`), runs `migrate:fresh --seed --seeder=E2ESeeder` and starts
`php artisan serve` on `E2E_PORT` (default 8795). Seeded accounts: `admin@example.com` and
`player@example.com`, password `password`. Add new seed records at the end of the seeder.
`tests/e2e/fixtures.ts` fails any test that logs a console error, so check 404s with
`page.request`, not `page.goto`.

**On this PC** `php` is a pvm `.bat` shim, which Node can't spawn: serve.mjs exits 1 with no
output. Run with the real binary:
`PHP_BINARY="C:/Users/shayp/.pvm/versions/php-8.3.36-Win32-vs16-x64/php.exe" npx playwright test`.

## Code map

- `App\Enums\Role` (`admin` / `player`), stored in `users.role`. Never mass assigned: only the
  admin pages and `app:create-admin` set it (`$user->role = …`).
- Admin pages: `routes/web.php` `admin.*` group, `auth` + `EnsureUserIsAdmin` (404 for
  non-admins, so players can't tell the pages exist), `Admin\UserController`, `UserPolicy`
  (admins can't delete themselves; `UpdateUserRequest` stops them demoting themselves).
  Vue pages in `resources/js/pages/admin/users`, shared fields in
  `components/admin/UserFields.vue` (native select with `:selected` on the options).
- Shared props: `auth.user` (without relations) and `auth.isAdmin` (sidebar "Users" link).
- `/version` (`App\Support\DeployedVersion`, copied from MDG): outside the web middleware,
  no cookies.
- Welcome page takes a `servers` prop (public servers); it's `[]` until the servers card.
- `App\Cloud` (#5281): `CloudProvider` interface (create/find/delete server, managed
  servers, server types, locations), `HetznerCloud` (Laravel `Http::`, token and SSH key path
  from `config('services.hetzner.*')`), `FakeCloudProvider` (state in the cache, so it survives
  `artisan serve`'s separate processes in E2E; `setStatus()` moves a fake VPS on). The binding
  in `AppServiceProvider` returns the fake in the `testing` and `e2e` environments whatever
  `HETZNER_FAKE` says, and `Tests\TestCase` calls `Http::preventStrayRequests()`.
  Every VPS gets `managed-by=minecraft-vps-manager` + `mvm-server-id=<id>`; `deleteServer()`
  refuses anything without the first. Login is SSH-key only: the ed25519 public key at
  `HETZNER_SSH_PUBLIC_KEY` is registered with Hetzner on first use (looked up by MD5
  fingerprint first). Volumes aren't in the interface: worlds go over SFTP (#5288).
  `tests/fixtures/ssh/id_ed25519.pub` is a throwaway public key (no private half exists).

## Rules for this app

- Never call a real cloud provider API from tests, CI or local E2E: every provider call goes
  through an interface with a fake. Creating a server costs real money on Shay's account.
- The local `.env` (gitignored) still holds a 2022 Linode token and root password (Shay is
  revoking it, #5278). Don't print it, don't use it. Tests and E2E don't read it.
- Dev containers publish on `127.0.0.1` only (there's no compose file now; Sail was removed).
