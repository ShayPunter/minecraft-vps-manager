# Minecraft VPS Manager

A web app that lets friends start a Minecraft server on its own cloud VPS when they want to
play. A watchdog checks the server every minute and, after 15 minutes with nobody online,
saves the world and deletes the VPS, so it only costs money while people play.

## Status

Being revived (November 2026) on Laravel 13, Hetzner Cloud and Laravel Forge. Done so far:
accounts and the admin user pages. Servers, the Hetzner client, the watchdog and world
storage come next.

- Sign-up is off. An admin creates every account (Admin → Users).
- Two roles: **admin** (manages users, later servers) and **player**.

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
