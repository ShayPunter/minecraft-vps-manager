# Minecraft VPS Manager

A web app that lets friends start a Minecraft server on its own cloud VPS when they want to
play. A watchdog checks the server every minute and, after 15 minutes with nobody online,
saves the world and deletes the VPS, so it only costs money while people play.

## Status

Being revived (November 2026) on Laravel 13, Hetzner Cloud and Laravel Forge. Done so far:
accounts, admin pages for users and servers, the Hetzner client, start, the watchdog, and
world storage: worlds live on an SFTP or FTP(S) server or in an S3-compatible bucket between
sessions (Admin → World storage). A start downloads the server's current world onto the VPS; a stop uploads a new
one before the VPS is deleted. Servers can't start until world storage is set up.

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

4. **Queue worker** (Forge → site → Queue): connection `database`, timeout `2800`, and
   **2 processes**. A stop zips the world and uploads it from the VPS (up to 45 minutes for
   a huge modpack), and a start waits for the VPS's first boot and downloads the world (up
   to 25 minutes). With one process, another server's start waits behind them.
5. **Scheduler** (Forge → site → Laravel scheduler, on). `servers:watch` runs every minute
   (idle stop, boot timeout) and `servers:sweep` every 10 minutes (orphan VPSes and SSH
   keys, retries).
6. **First admin**: `php artisan app:create-admin --name="Your Name" --email=you@example.com`
   (a password is generated and printed), then `php artisan app:users` to check.
7. **World storage**: in the app, Admin → World storage. Pick SFTP (a Hetzner Storage Box
   or your own server; a sub-account limited to one folder is best, since each VPS gets
   these credentials) or FTP (TLS on by default), fill in host, port, user, password or
   private key and a base folder, then **Test and save**. Saving tests the connection,
   pins the SFTP host key and stores the secrets encrypted with `APP_KEY`. Each VPS gets
   the login only while Minecraft isn't running (before the start, after the stop), in a
   root-only folder that is deleted when the transfer ends.
   Or pick **S3-compatible** (Hetzner Object Storage, Cloudflare R2, Backblaze B2, AWS,
   MinIO): endpoint (empty for AWS), region, bucket, access key, secret key and an optional
   folder prefix; tick "Path-style URLs" for MinIO-style servers. A key limited to that one
   bucket is best. With S3 the VPS never gets the keys: the app gives it pre-signed links
   (one download link on start; on stop, links for a multipart upload in 128 MB parts, the
   completion and a read-back) that expire after the job. Worlds up to 50 GB fit
   (`MVM_S3_MAX_WORLD_GB`, `MVM_S3_PART_MB`). A bucket lifecycle rule that aborts incomplete
   multipart uploads after a day cleans up after a VPS that died mid-save.
   For world/modpack uploads bigger than PHP's default 2 MB, raise `upload_max_filesize`
   and `post_max_size` (Forge → server → PHP) and nginx's `client_max_body_size` to match
   `MVM_WORLD_UPLOAD_MAX_MB` (default 4096).
8. **DNS (optional)**: give servers a name like `atm9.example.com` instead of an IP. The domain
   must be on Cloudflare. In Cloudflare, My Profile → API Tokens → Create Token → the
   **Edit zone DNS** template, Zone Resources: Include → Specific zone → your domain. Copy
   the token, and the **Zone ID** from the domain's Overview page (API section). In the app,
   Admin → DNS: domain, Zone ID and token, then **Test and save** (it makes and deletes a
   `_mvm-test-…` TXT record). Then set a **Subdomain** on a server in Admin → Servers.
   While that server runs, an A record (DNS only, TTL 60) points the name at its VPS; it
   is deleted when the server stops. Records the app makes carry
   `managed-by=minecraft-vps-manager` in their comment; records you made yourself are never
   changed, and a name that already has one is left alone (players get the IP instead).
9. **Check it**: `php artisan app:doctor`. It tries the token with one read-only call, checks
   the app can make SSH keys, has the worker run a test job, checks the scheduler ran in the last
   3 minutes, tests world storage (writes and deletes a small file) and DNS if it's set up, and says where alerts go. It prints no secrets and exits 1 if anything fails (DNS only warns).

### How a start works

Before the first start, tick **I accept the Minecraft EULA for this server** in the server's
settings: Minecraft won't run without it, and the app then writes `eula.txt` for you.

Pick the **Software**: **Vanilla**, **Paper** or **Fabric** at a Minecraft version (releases
from 1.17 on, newest first, from Mojang's, PaperMC's and Fabric's own version lists), or
**My own start command** for a modpack server pack or a world you upload. For Vanilla, Paper
and Fabric the app sets the Java version (what Mojang or PaperMC say the version needs) and
the start command, with the memory flags sized to the server type's RAM. The first start of
a server with no saved world installs it on the VPS: the server jar (Paper's newest stable
build; for Fabric the vanilla jar plus Fabric's installer, which sets up the newest stable
loader), each checked against the SHA-1 or SHA-256 its source publishes. The first stop then
saves the whole folder like any world, so later starts download that and never reinstall.
Changing the version afterwards doesn't touch a saved world.

The server's settings also hold **server.properties** (MOTD, difficulty, game mode, max
players, PvP, whitelist, view and simulation distance, spawn protection, flight, hardcore,
plus any other `key=value`), the **whitelisted players** and **operators** (names are
checked against Mojang), and a pack's **run.sh** and **user_jvm_args.txt**. They start from
what the saved world has (read from an upload, or recorded on every stop). Only what you
change is kept, and it's written over the world's files before every start, so a running
server picks it up on its next start. "Use the pack's file" puts a file back to the pack's.

With your own start command, set the Java version the server jar needs (Paper and vanilla
26.x need Java 25). A `run.sh` at the top of the world zip is made executable, so `./run.sh`
works as the start command.

**Admin → Servers → Log** shows what the VPS and Minecraft printed, live while it starts or
runs, and still there after the VPS is deleted (14 days). If a server never comes online, the
app pulls the last log lines before deleting the VPS and names the likely cause (EULA, Java
version, memory, a missing jar…) in the error.

The VPS boots and installs Java (cloud-init). The app then logs in over SSH, downloads the
server's current world archive straight from world storage onto the VPS, checks its size and
SHA-256, unpacks it into the server folder (a zip holding a single folder, like most modpack
server packs, is unpacked from inside that folder) and starts Minecraft. A server with no
saved world starts with a new one. If the download or the check fails, the start fails and
the VPS is deleted.

### How a stop works

After a server's idle minutes with nobody on (default 15, set per server; boot time doesn't
count), the watchdog runs `systemctl stop` on the VPS over SSH. That saves the world. When
systemd reports a clean stop, the VPS zips the server folder, uploads it to world storage,
downloads it again and compares the SHA-256, and the app checks the size on storage. Only
then does it become the server's current world (the newest 3 are kept) and the VPS is deleted. If any step fails, the VPS is **kept**
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
