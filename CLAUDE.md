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
- Servers (#5282): `Server` (definition, route key = slug, `SLUG_PATTERN`) and `ServerRun`
  (one VPS lifetime). `server_runs.active_server_id` equals `server_id` while a run is
  active and is unique, so the database itself refuses two active runs for one server.
  Every status change goes through `App\Servers\ServerLauncher` (provisioning → booting →
  starting → online; stopping → stopped; failed): `start()` takes a cache lock, checks the
  cap (`config('minecraft.max_running')`, `MVM_MAX_RUNNING`, default 2) and queues
  `ProvisionServerRun` (one try: a retry could make a second VPS). `advanceActive()` runs on
  dashboard/home page views (throttled by `MVM_CHECK_INTERVAL`, 10 s; E2E sets 0) and every
  minute via the scheduled `servers:advance`. "Online" = Minecraft's port takes a TCP
  connection (`MinecraftProbe`; `FakeMinecraftProbe` in testing/e2e, `refuse()` in tests).
  In E2E the fake VPS boots on its first check (`FakeCloudProvider` `autoBoot`).
- `App\Servers\CloudInit`: user data as `#cloud-config` + JSON (valid YAML), files base64,
  commands as argv lists. The admin's start command is the only thing a shell sees (one
  line, in `/usr/local/bin/mvm-start` on the VPS); the unit is `minecraft-<slug>.service`.
  Don't use `escapeshellarg()` there: on Windows it double-quotes, and the script runs on Linux.
- Start is `POST /servers/{slug}/start` (`throttle:server-start`, 5/min per user or IP).
  Public servers can be started by guests (#5276); private ones 404 for guests.
- Watchdog (#5283, `App\Servers\Watchdog`): `servers:watch` every minute (advance starting
  runs; give up on runs not online `MVM_BOOT_TIMEOUT_MINUTES` (20) after Start, VPS deleted,
  `failed`; ping online runs and stop one after the server's `idle_minutes` (default 15)
  with nobody on, counted from `online_at` or the last ping with players, so boot time never
  counts). `servers:sweep` every 10 minutes (stuck `stopping` → `stop_failed`; retry
  `stop_failed`; delete managed VPSes no active run knows, matching the run id at the end of
  the VPS name so a run still provisioning isn't hit; one alert per run older than
  `MVM_MAX_RUN_HOURS` (8)). Players come from Minecraft's server-list ping
  (`TcpMinecraftProbe::playersOnline`, checked against a real vanilla server in Docker);
  no answer counts as idle.
- Stopping: `ServerLauncher::requestStop()` sets `stopping` (atomic update, so two clicks
  or the watchdog can't double up) and queues `StopServerRun` (1 try, 600 s timeout; the
  database queue's `retry_after` defaults to 900 for it). `stop()`: a run that was ever
  online first gets `CloudInit::stopCommand()` over SSH as root (`systemctl stop` = SIGTERM,
  Minecraft's shutdown hook saves the worlds; the unit has `SuccessExitStatus=0 143` and
  `TimeoutStopSec=300`, and the command fails unless systemd's `Result` is `success`), then
  the VPS is deleted. Any failure → `stop_failed`: the VPS is **kept**, still counts towards
  the cap, one alert (`Alerter`: `MVM_ALERT_MAIL` or every admin, plus
  `MVM_ALERT_DISCORD_WEBHOOK`). Admin → Servers then offers Retry stop and "Delete without
  saving" (`force=1`, only for `stop_failed`). World upload (#5288) goes between the SSH
  stop and the delete. `provision()` deletes a VPS that arrives after its run ended.
- `app:doctor` (#5285): APP_URL https, Hetzner token (one read-only `locations()` call),
  SSH pair readable and matching (phpseclib derives the public half), queue worker (queues
  `QueueHeartbeat` and waits `--wait` s, 15 by default), scheduler (`servers:watch` beat in
  the last 3 minutes), an admin exists, where alerts go. `App\Support\Heartbeat` keeps the
  beats in the cache as ISO strings; `servers:sweep` also queues a `QueueHeartbeat`. FAIL =
  exit 1, WARN doesn't fail. Never prints a secret (tested). The Forge setup steps are in the README.
- Who can stop: admins (Admin → Servers, or the dashboard), and the signed-in user who
  started the run (`POST /servers/{slug}/stop`, 404 for anyone else; `ServerPresenter::mayStop`).
  Guest-started public servers stop by the watchdog or an admin.
- SSH: `RemoteShell` interface, `SshRemoteShell` (phpseclib 3, root, key at
  `HETZNER_SSH_PRIVATE_KEY`, default `storage/app/private/ssh/id_ed25519`; no host-key check,
  every VPS is new and Hetzner doesn't report its key), `FakeRemoteShell` in testing/e2e
  (records commands in the cache; `failWith()`, `unreachable()`). Checked against a real
  sshd in Docker: exit codes, output, timeout, wrong key, public key given as private.
- `ServerCatalog` caches the provider's types/locations for an hour as plain arrays
  (`config/cache.php` has `serializable_classes => false`, so objects can't be cached).
  Default for new servers: cheapest non-deprecated type with ≥ 8 GB in the first German location.
- The welcome page has no app layout, so it mounts its own `<Toaster />`.
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
