# Minecraft VPS Manager

A web app that lets friends boot a Minecraft server on a cloud VPS on demand. A watchdog
pings the server every minute and, after 15 minutes with no players, stops it and deletes
the VPS so it only costs money while people play. Shay ran it in 2022 on Linode; it's being
revived (Nov 2026) on **Hetzner Cloud** and **Laravel 13**, hosted on **Forge**.

Repo: `ShayPunter/minecraft-vps-manager` (public, Shay's personal account: don't transfer).
GroundStation tag: `minecraft vps manager`. See the control repo's `CLAUDE.md` for the board,
feature workflow and Laravel/Forge notes, which all apply here.

## State of the code (as received)

- Laravel 10 (Laravel Shift, Dec 2023, from Laravel 9), Jetstream 2 (Inertia + Vue 3),
  Vite 2, Tailwind 3, Sanctum 3, `bensampo/laravel-enum`, phpseclib 3 (SSH),
  `xpaw/php-minecraft-query` (server ping). All end of life; 72 composer advisories and
  22 npm advisories at onboarding. The plan is a **port onto the Laravel 13 Vue starter kit**
  (see the board), not an in-place upgrade.
- `main` = the old `admin-panel` branch (fast-forwarded at onboarding). The 2022 `main`
  was the Linode-only version; `admin-panel` added the admin users/servers pages and the
  Laravel 10 Shift. The `shift-106991` branch is already merged.
- Domain code:
  - `app/Http/Controllers/LinodeController.php`: creates a Linode (Ubuntu 22.04, root
    password from `LINODE_PASS`), stores a `Node` row, marks the `Server` provisioning.
  - `app/Console/Commands/ServerProvisionStatus.php` (`server:provision_status`, every
    minute): polls Linode; when running, queues `StartServer` on the `server_start` queue.
  - `app/Jobs/StartServer.php`: SSH as root (password), starts `run.sh` in a `screen`
    named after the server under `/mnt/Minecraft/<server>`.
  - `app/Console/Commands/check_server_status.php` (`server:heartbeat`, every minute):
    pings port 25565, counts idle minutes in `servers.last_activity`, at 15 stops and
    deletes the Linode.
  - `app/Http/Controllers/ServerController.php`: admin "new server" form (name, provider,
    size, public flag, uploaded world/modpack file, icon). `destroy()` is a stub (meant to
    save the world to S3).
  - Admin API in `routes/api.php`, guarded by `AdminAPI` (`?api_token=` of an admin user).
- Known problems (on the audit card): `/server/provision/{name}` creates a paid VPS with
  **no authentication**; server names go unescaped into shell commands; the watchdog sends
  `stop` to a screen called `server` while `StartServer` names it after the server, so the
  world is never saved before the VPS is deleted; root password SSH; `env()` called
  outside config; uploads up to 10 GB on the public disk.

## Commands (current Laravel 10 code)

- Install: `composer install`, `npm ci --legacy-peer-deps` (the lockfile has
  laravel-vite-plugin 0.8 next to vite 2, which plain `npm ci` refuses).
- Build: `npm run build`.
- Tests: `php artisan test` (PHPUnit; `phpunit.xml` forces in-memory SQLite). 24 pass, 7
  skip (Jetstream features that are switched off).
- No linter configured yet (`.styleci.yml` only); Pint comes with the port.
- No E2E yet; Playwright comes with the port (`php artisan serve` on a throwaway SQLite
  file, as in the other Laravel apps).

## Rules for this app

- Never call a real cloud provider API from tests, CI or local E2E: every provider call goes
  through an interface with a fake. Creating a server costs real money on Shay's account.
- The local `.env` (gitignored) still holds a 2022 Linode token and root password. Don't
  print it, don't use it.
- `docker-compose.yml` (Sail) publishes on `127.0.0.1` only.
