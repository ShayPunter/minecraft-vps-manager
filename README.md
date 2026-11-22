# Minecraft VPS Manager

A web app that lets friends start a Minecraft server on its own cloud VPS when they want to
play. A watchdog checks the server every minute and, after 15 minutes with nobody online,
saves the world and deletes the VPS, so it only costs money while people play.

## Status

Being revived (November 2026) on Laravel 13, Hetzner Cloud and Laravel Forge. Done so far:
accounts, admin pages for users and servers, the Hetzner client, start, and the watchdog.
World storage over SFTP (set in Admin → World storage) comes next. Until then each start
begins with a fresh world.

- Sign-up is off. An admin creates every account (Admin → Users).
- Two roles: **admin** (manages users and servers) and **player**.
- Public servers can be started from the home page by anyone; private ones need an account.
  At most `MVM_MAX_RUNNING` (2) run at once.

## Hosting on Forge

1. **Site.** A Laravel site on PHP 8.4 for the domain, deploying `main` (push to deploy on).
   SQLite is fine (`DB_CONNECTION=sqlite`, the default file in `database/`). The deploy
   script runs `php artisan migrate --force`.
2. **Hetzner Cloud.** Make a project for these servers and a **Read & Write** API token
   (Security → API tokens). Every VPS the app makes carries the label
   `managed-by=minecraft-vps-manager`; the app never touches anything without it.
   There's no SSH key to make: the app makes a fresh key pair for every VPS, registers it
   with Hetzner (same label), logs in with it to save the world, and deletes it with the VPS.
   The private half is stored encrypted with `APP_KEY`, so keep `APP_KEY` as it is.
3. **Environment** (Forge → site → Environment):

    | Key                         | Meaning                                                              |
    | --------------------------- | -------------------------------------------------------------------- |
    | `APP_URL`                   | `https://` + the domain                                              |
    | `HETZNER_TOKEN`             | the token from step 2                                                |
    | `QUEUE_CONNECTION`          | `database` (starts and stops run on the queue)                       |
    | `MAIL_*`                    | a real mailer, so alerts arrive (with `log` they only reach the log) |
    | `MVM_ALERT_MAIL`            | where alerts go; empty = every admin                                 |
    | `MVM_ALERT_DISCORD_WEBHOOK` | optional: alerts in a Discord channel too                            |
    | `MVM_MAX_RUNNING`           | VPSes at once, across all servers (default 2)                        |
    | `MVM_MAX_RUN_HOURS`         | alert once when a run is older than this (default 8)                 |
    | `MVM_BOOT_TIMEOUT_MINUTES`  | give up on a start that isn't online by then (default 20)            |

4. **Queue worker** (Forge → site → Queue): connection `database`, timeout `660`. A stop
   saves the world over SSH first, which can take a few minutes for a big modpack.
5. **Scheduler** (Forge → site → Laravel scheduler, on). `servers:watch` runs every minute
   (idle stop, boot timeout) and `servers:sweep` every 10 minutes (orphan VPSes and SSH
   keys, retries).
6. **First admin**: `php artisan app:create-admin --name="Your Name" --email=you@example.com`
   (a password is generated and printed), then `php artisan app:users` to check.
7. **Check it**: `php artisan app:doctor`. It tries the token with one read-only call, checks
   the app can make SSH keys, has the worker run a test job, checks the scheduler ran in the last
   3 minutes, and says where alerts go. It prints no secrets and exits 1 if anything fails.

### How a stop works

After a server's idle minutes with nobody on (default 15, set per server; boot time doesn't
count), the watchdog runs `systemctl stop` on the VPS over SSH. That saves the world. Only
when systemd reports a clean stop is the VPS deleted. If any step fails, the VPS is **kept**
(it still costs money), an alert goes out, and the watchdog retries every 10 minutes.
Admin → Servers shows the error, with Retry stop or Delete without saving. Admins and the
person who started a server can also stop it by hand.

## Development

Requires PHP 8.3+, Composer and Node 24.

```bash
composer install
npm ci
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan app:create-admin     # the first admin account
npm run build
composer run dev                 # app + Vite at http://localhost:8000
```

Checks (the same as CI):

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm run check
npm run types:check
php artisan test
npm run test:e2e                 # Playwright, on a throwaway SQLite file
```

## Licence

MIT. See [LICENSE](LICENSE).
