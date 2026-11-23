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
PHPUnit 13 (the kit's choice, not Pest; 13 since #5432). PHPUnit 13 needs **PHP 8.4**: with
the dev packages installed, any `php artisan` on 8.3 (even `npm run build`'s
`wayfinder:generate`) dies on a parse error in `vendor/phpunit`. The app itself still runs
on 8.3 (`composer check-platform-reqs --no-dev` passes; Forge installs `--no-dev`). On this
PC put the 8.4 folder first on `PATH` (`~/.pvm/versions/php-8.4.27-Win32-vs17-x64`) and use
its `composer/composer.phar`. TypeScript stays on 6.x (Dependabot ignores >=7: vue-tsc
can't run on TS 7's native compiler). The 2022/2023 Laravel 10 / Jetstream / Linode code
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
`PHP_BINARY="C:/Users/shayp/.pvm/versions/php-8.4.27-Win32-vs17-x64/php.exe" npx playwright test`.
`two-factor.spec` (#5432) signs in through the 2FA challenge (users seeded with
`E2ESeeder::TOTP_SECRET`, codes made in the spec); its "paste" is `keyboard.insertText`, since
a real paste would use the machine's clipboard.

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
  Guest starts (no user: the public card or a share link) are limited in
  `App\Servers\GuestStarts` (#5526), checked inside `start()`'s lock after the cap:
  `minecraft.guest_starts_per_day` per server per UTC day (runs with `started_by` null,
  failed ones included; 0 = sign-in only) and `guest_free_slots` of `max_running` left free.
  `GuestStarts::props()` feeds the admin form's `data-test="guest-starts"` line.
- Visibility (#5462, `App\Enums\Visibility`, `servers.visibility`, replaced `is_public`):
  public (home page, guests start it), private (every signed-in dashboard), link (only
  admins' dashboard and Admin, plus its share page). A link-only server always has a share
  token (`Server::booted()` saving hook: made when it becomes link only, wiped when it stops
  being one, so switching away kills the link); 40 chars, `share_token` encrypted (Admin
  shows the link again) + `share_token_hash` (SHA-256, unique, the lookup), both `$hidden`.
  `SharedServerController`: `GET /s/{token}` (Inertia page `Shared`, one `ServerCard` with
  `share-token`, `Referrer-Policy: no-referrer` + `X-Robots-Tag: noindex`) and
  `POST /s/{token}/start` (`throttle:server-start`, same `ServerStartController::launch()`).
  Unknown/old token or a non-link server = 404. The slug start route 404s link-only servers
  for everyone but admins. Guests with the link can't stop; a signed-in player who started
  it can (the usual starter rule). Admin → Servers → Edit: copy + "New link"
  (`POST /admin/servers/{slug}/share-link`).
- Watchdog (#5283, `App\Servers\Watchdog`): `servers:watch` every minute (advance starting
  runs; give up on runs not online `MVM_BOOT_TIMEOUT_MINUTES` (20) after Start, VPS deleted,
  `failed`; ping online runs and stop one after the server's `idle_minutes` (default 15)
  with nobody on, counted from `online_at` or the last ping with players, so boot time never
  counts; `ServerRun::idleStopsAt()`, which `ServerPresenter` also sends as `stops_at` +
  `stops_in_seconds` so the pages count down to the same minute with `useCountdown`, #5378).
  Start estimates (#5379, `App\Servers\BootEstimate`): median Start → Online of the
  server's last 5 runs that came online, installs (installable software, run without a
  world archive) and ordinary starts counted apart, else the newest 20 across all servers.
  The presenter sends `typical_boot_seconds` (stopped or starting) and
  `boot_elapsed_seconds` (starting); `ServerCard` shows "Usually ready in about N min.",
  "About N min left." and "Any moment now.".
  `servers:sweep` every 10 minutes (stuck `stopping` → `stop_failed`; retry
  `stop_failed`; delete managed VPSes no active run knows, matching the run id at the end of
  the VPS name so a run still provisioning isn't hit; one alert per run older than
  `MVM_MAX_RUN_HOURS` (8)). Players come from Minecraft's server-list ping
  (`TcpMinecraftProbe::playersOnline`, checked against a real vanilla server in Docker);
  no answer counts as idle.
- Stopping: `ServerLauncher::requestStop()` sets `stopping` (atomic update, so two clicks
  or the watchdog can't double up) and queues `StopServerRun` (1 try, 2700 s timeout; the
  database queue's `retry_after` defaults to 3600 for it). `stop()`: a run that was ever
  online first gets `CloudInit::stopCommand()` over SSH as root (`systemctl stop` = SIGTERM,
  Minecraft's shutdown hook saves the worlds; the unit has `SuccessExitStatus=0 143` and
  `TimeoutStopSec=300`, and the command fails unless systemd's `Result` is `success`), then
  the VPS is deleted. Any failure → `stop_failed`: the VPS is **kept**, still counts towards
  the cap, one alert (`Alerter`: `MVM_ALERT_MAIL` or every admin, plus
  `MVM_ALERT_DISCORD_WEBHOOK`). Admin → Servers then offers Retry stop and "Delete without
  saving" (`force=1`, only for `stop_failed`). World upload (#5288) goes between the SSH
  stop and the delete (WorldSync below). `provision()` deletes a VPS that arrives after its run ended.
- `app:doctor` (#5285): APP_URL https, Hetzner token (one read-only `locations()` call),
  the app can make an ed25519 pair (nothing to set up since #5324), queue worker (queues
  `QueueHeartbeat` and waits `--wait` s, 15 by default), scheduler (`servers:watch` beat in
  the last 3 minutes), an admin exists, world storage (the real connection test, #5288),
  where alerts go. `App\Support\Heartbeat` keeps the
  beats in the cache as ISO strings; `servers:sweep` also queues a `QueueHeartbeat`. FAIL =
  exit 1, WARN doesn't fail. Never prints a secret (tested). The Forge setup steps are in the README.
- Run errors (`server_runs.error`: script output, SSH/provider messages, storage user@host,
  IPs) go to admins only (#5527): `ServerPresenter::errorFor()` gives everyone else
  `PUBLIC_ERROR` on the cards and in the start/stop toasts; the label still shows.
- Who can stop: admins (Admin → Servers, or the dashboard), and the signed-in user who
  started the run (`POST /servers/{slug}/stop`, 404 for anyone else; `ServerPresenter::mayStop`).
  Guest-started public servers stop by the watchdog or an admin.
- SSH: `RemoteShell` interface, `SshRemoteShell` (phpseclib 3, root), `FakeRemoteShell` in
  testing/e2e (records commands, the key each used and the host key it was pinned to in the
  cache; `failWith()`, `unreachable()`). Host keys are pinned (#5527): `provision()` makes a
  second ed25519 pair per run, cloud-init gets it (`ssh_keys`, `ssh_deletekeys`,
  `ssh_genkeytypes: []`), only `server_runs.ssh_host_key` (`ssh-ed25519 AAAA…`, public) is
  kept, and every `run()`/`putFiles()` offers only that type and refuses ("didn't show the
  SSH host key made for this VPS") before logging in. A prepare that hits it retries like a
  refused connection until the boot timeout. Runs from before #5527 (null) aren't checked.
  Checked for real (Nov 23): `ubuntu:24.04` + cloud-init (NoCloud seed with the app's real
  user data) + sshd ended with only the app's key; SshRemoteShell from `php:8.4-cli` ran and
  wrote files pinned to it and refused a different one. `SshHostKeyIntegrationTest` runs
  against CI's atmoz/sftp service.
  Checked against a real sshd in Docker: exit codes, output, timeout, wrong key, public key
  given as private, and a key from `SshKeyPair::generate()` (#5324).
- SSH keys (#5324): **every run makes its own pair** (`App\Servers\SshKeyPair`, phpseclib,
  no shell) in `ServerLauncher::provision()`. The private half is saved on the run first
  (`server_runs.ssh_private_key`, `encrypted` cast = APP_KEY, `$hidden`), then the public half
  is registered as `mvm-<slug>-<run>` with `managed-by` + `mvm-run-id` labels
  (`provider_ssh_key_id`), then the VPS is created with only that key (`ServerSpec::$sshKeyIds`;
  both providers refuse a spec without one, since Hetzner would email a root password).
  `stop()` passes the run's key to the shell. `ServerLauncher::end()` (stopped/failed, i.e.
  the VPS is gone) deletes the key at the provider and wipes both columns; `stop_failed`
  keeps it for Retry stop. A failed key delete is only logged: the sweep's
  `deleteOrphanKeys()` removes managed keys no active run uses (by id or `mvm-run-id`
  label), never unlabelled ones. A run from before #5324 has no key and falls back to the
  old file at `services.hetzner.legacy_ssh_private_key_path` (not an env var any more).
  `provision()` re-reads the run first, so a late job never writes back a stale status.
- `ServerCatalog` caches the provider's types/locations for an hour as plain arrays
  (`config/cache.php` has `serializable_classes => false`, so objects can't be cached).
  Default for new servers: cheapest non-deprecated type with ≥ 8 GB in the first German location.
  Hetzner marks retired types **per location** (`server_types[].locations[].deprecation`;
  the type-wide `deprecated` was removed on 2 Nov 2026, and reading it showed CPX11/CPX21 as
  current, #5321). `ServerType::$grossPrices` holds only locations where the type can still be
  ordered (monthly + hourly gross), `$retiredIn` the deprecated ones, `$category` Hetzner's
  grouping ("Shared vCPU"; falls back to CPU type). The admin form groups by it in
  `<optgroup>`s, cheapest first. The cache key is versioned (`mvm:catalog:v2`): bump it when
  `ServerType`'s fields change, or the cached arrays no longer fit the constructor.
  E2E's `server-types.spec` signs in as `catalog-admin@example.com` (Fortify's login limit).
- The welcome page has no app layout, so it mounts its own `<Toaster />`.
- World storage (#5288, `App\WorldStorage`): one SFTP or FTP(S) server, set in Admin →
  World storage (`WorldStorageSetting`, one row; password and private key `encrypted` +
  `$hidden`, never sent to the page: it only gets `has_password`). The env
  (`config/world_storage.php`, `WORLD_STORAGE_*`) is the fallback while no row exists.
  `WorldStorage::settings()` throws `StorageUnavailable` (message safe to show) when nothing
  is set up, SFTP has no pinned host key, or APP_KEY changed (DecryptException). Saving
  always runs `test()` first: SFTP fetches the host key with phpseclib, refuses a different
  one than the saved key for the same host+port unless "Trust the new host key" is ticked,
  then writes/sizes/deletes `.mvm-connection-test/probe` (in a folder, so a missing base
  folder gets made). Flysystem pins it too (`hostFingerprint` = `sha256:<base64>`).
  Host, user and base folder are pattern-checked (`StorageSettings::*_PATTERN`, no `..`),
  because part 2 puts them in a shell script on the VPS. In testing/e2e (or
  `WORLD_STORAGE_FAKE`) the disk is a local folder (`WORLD_STORAGE_FAKE_ROOT`; E2E uses
  `%TEMP%/mvm-e2e/world-storage`, emptied by serve.mjs), the host key is
  `WorldStorage::FAKE_HOST_KEY` and host `unreachable.invalid` fails the test.
  `Archives`: `WorldArchive` rows + files under `<slug>/` (`Ymd-His-<rand>-upload.zip`),
  `servers.current_world_archive_id` = what the next start downloads, only the newest 3
  kept (`prune()`, never the current one). Uploads go through `ZipInspector` (no absolute
  paths, `..`, backslashes, drive letters or symlinks), are refused while a run is active,
  and are size-checked on storage before they count. Downloads stream through the app.
  Deleting a server keeps its archives unless `delete_archives` is ticked.
  `SftpIntegrationTest` runs against CI's `atmoz/sftp` service (`MVM_SFTP_TEST_*`); locally,
  run it in `php:8.4-cli` on a Docker network with `atmoz/sftp:alpine` (no published port).
  `league/flysystem-ftp` needs ext-ftp, which this PC's PHP lacks: `composer` commands here
  need `--ignore-platform-req=ext-ftp` (CI and Forge have it).
- World sync (#5288 part 2, `App\Servers\WorldSync`): two sh scripts the app writes to
  `/root/.mvm` over SFTP (`RemoteShell::putFiles()`, phpseclib; parent folder 0700, files
  created empty with their mode before the contents) and runs as root. **prepare.sh**
  (`PrepareServerRun`, queued once by `advance()` when the VPS is running, guarded by
  `server_runs.prepare_queued_at`): `cloud-init status --wait`, download the current archive,
  check size + SHA-256 against the `WorldArchive` row, `check_zip` (the upload rules, #5527),
  unzip (a zip holding one folder is unpacked from inside it), remove any symlink in the
  folder (`WorldSync::unlinkScript()`, before root writes eula.txt / server.properties /
  user_jvm_args.txt; `applyScript()` also `rm -f`s each file before writing), `systemctl start`. Cloud-init no longer enables or starts the
  unit. Minecraft is probed only once `prepared_at` is set. Can't connect (sshd not up) or
  exit 75 (another script holds the `flock`) → `prepare_queued_at` cleared, tried on the
  next check; any other failure → `abandon()` (VPS deleted, run failed). **save.sh** (in
  `stop()`, after the clean `systemctl stop`, for runs with `prepared_at` or `online_at`):
  refuses while the unit is active, removes symbolic links (each named in the output, #5527)
  and zips the folder (`-y`, so zip never follows one),
  uploads `<path>.part`, downloads it again and compares the SHA-256, renames, prints
  `size=… sha256=…` (or `empty=1` for an empty folder: nothing saved, the stop goes on).
  The app then checks the size on storage with Flysystem and `Archives::record()`s it
  (`source=save`, `server_runs.saved_world_archive_id`, so a retried stop after a failed
  delete doesn't save twice). Any failure → `stop_failed`, VPS kept. SFTP uses OpenSSH's
  `sftp` with a `known_hosts` holding the pinned key (`StrictHostKeyChecking=yes`); a
  password goes through `SSH_ASKPASS` (`SSH_ASKPASS_REQUIRE=force`), and `-o BatchMode=no`
  must come **before** `-b` or sftp's own BatchMode=yes wins. FTP uses curl with a
  `--config` file (`user = "u:p"`, `\` and `"` escaped), `--ssl-reqd` for FTPS (curl checks
  the certificate, PHP's FTP doesn't), `%2F` for an absolute base folder, rename with
  `-Q "-RNFR …" -Q "-RNTO …"`. Secrets are only on the VPS while Minecraft isn't running and
  never in a command line; the trap deletes them. Starts are refused (`StartRefused::noWorldStorage`)
  while `WorldStorage::settings()` throws, and `app:doctor` runs the connection test.
  Timeouts: prepare 1200 s (2400 s for a pack install; job 2700), save 2400 s (StopServerRun 2700), queue `retry_after`
  3600, `stuck_stop_minutes` 50.
  `FakeRemoteShell` answers prepare.sh with "prepared" and save.sh by writing a small zip to
  the fake storage at the given path; `failMatching()`, `emptyWorld()`, `forget()`
  (clears what the start recorded). Tests that start servers `use UsesFakeWorldStorage`
  (`tests/Concerns`) and call it in `setUp`.
  Checked for real (Nov 22) from an `ubuntu:24.04` container (openssh-client, zip, unzip,
  curl; systemctl/cloud-init/java stubbed) on a private Docker network: `atmoz/sftp:alpine`
  with a password holding a space, a quote and a backslash, and with a private key;
  `delfer/alpine-ftp-server` (plain FTP, `chpasswd` for an odd password). Save, read-back,
  rename, download, SHA-256 refusal, running-server refusal, wrong host key, single-folder
  zip. `putFiles()` against an alpine sshd (`openssh-sftp-server` needed) from `php:8.4-cli`.
  FTPS wasn't checked for real (no certificate in the test setup).
- S3-compatible world storage (#5347): driver `s3` in the same `WorldStorageSetting` row.
  The access key is `username`, the secret key `password` (so keep/remove/hidden work as for
  SFTP), `root` is the key prefix (may be `''`), and `host`/`port` are derived from the
  endpoint for messages only (`StorageSettings::endpointHostAndPort`; no endpoint = AWS).
  New columns `endpoint`, `region`, `bucket`, `path_style`. The disk is
  league/flysystem-aws-s3-v3; `StorageSettings::s3ClientConfig()` sets
  `request_checksum_calculation`/`response_checksum_validation` to `when_required` (the SDK's
  default CRC checksums break several S3-compatibles and pre-signed part uploads).
  **The VPS never gets S3 keys.** `App\WorldStorage\S3Transfers`: `downloadUrl()` (pre-signed
  GET, prepare_timeout + 600 s) for prepare.sh, `startUpload()` (the app calls
  CreateMultipartUpload, then pre-signs UploadPart 1..n, CompleteMultipartUpload and a
  read-back GET; n = s3_max_world_gb / s3_part_mb) for save.sh, `abort()` (abort + delete) on
  every save that doesn't count, empty worlds included. Links go to the VPS as curl config
  lines (`url = "…"`, `WorldSync::curlUrl` refuses quotes, spaces, backslashes) in
  `get.conf`, `parts` (one line per part), `complete.conf`; never in a command line. The S3
  save.sh cuts the zip with `dd` one part at a time (no second copy on disk), reads each
  ETag from `curl -D`, POSTs the XML, checks the body for `CompleteMultipartUploadResult`
  (S3 can answer 200 with an error), then downloads and compares the SHA-256. No `.part` +
  rename: an unfinished multipart upload is invisible. Exits: 32 world bigger than the links,
  33 no ETag, 34 completion refused, 31 read-back differs. In testing/e2e the links point at
  `fake-s3.invalid` and `fakeLog()` records started/aborted uploads; the fake secret
  `wrong-password` fails the connection test like SignatureDoesNotMatch.
  `S3IntegrationTest` runs against CI's `adobe/s3mock:5.2.3` service (`MVM_S3_TEST_ENDPOINT`).
  Checked for real (Nov 22) on a private Docker network: s3mock, `php:8.4-cli` (+ ext-zip via
  `docker-php-ext-install`, the tree piped in, `MVM_S3_SCRIPT_DIR` writes the VPS files with
  real links into a shared volume) and `ubuntu:24.04` running prepare.sh/save.sh (stubbed
  systemctl/cloud-init/java): wrong hash refused, single-folder zip unpacked, a 12 MB world in
  three 5 MB parts, too-big refused, running world refused, then `MVM_S3_SCRIPT_CHECK`
  checked size + hash on the mock. s3mock doesn't check signatures, so a wrong key and link
  expiry weren't tested for real; nor was a real provider.
  E2E: "Bucket" also matches the "Path-style URLs (bucket in the path)" label: use `exact: true`.
- Run logs (#5367, `App\Servers\RunLog`, `server_run_logs`): every run keeps what its VPS
  printed, after the VPS is gone. Sources: `prepare` (prepare.sh's output, added by the app
  after each attempt), `setup` (cloud-init-output.log, sent once) and `minecraft` (the unit's
  journal) from the **log shipper**, and `fallback` (the app's own SSH pull before deleting a
  VPS that never came online). The shipper: `RunLog::shipperFiles()` puts `ship.sh`,
  `curl.conf` (URL + bearer token, 0600) and the unit in `/root/.mvm-log` (putFiles chmods
  every parent folder to 0700, so the unit can't be written into /etc/systemd/system: prepare.sh
  `install`s it from there, right after the cloud-init wait). ship.sh polls
  `journalctl -u <unit> --show-cursor --after-cursor` every 5 s and POSTs new lines; the cursor
  is saved only after a 2xx, so nothing is lost or sent twice across restarts. It exits on
  410/403/404, i.e. only when it next has lines to send after the run ended (runs end once the
  VPS is deleted anyway). Intake: `POST /runs/{run}/log` (`RunLogIntakeController`, outside
  the web middleware like /version: no session, no CSRF, no cookie; `throttle:run-log` 60/min per
  run **and IP** plus 300/min per IP (#5527: it counts before the token check, so a run-only
  key let anyone 429 the real shipper); 404 for an unknown run or a wrong token alike, 410 once ended, 413 over 512 KB). A fresh
  token per prepare attempt, only its SHA-256 on the run (`log_token_hash`, `$hidden`). Lines:
  CR stripped, empty skipped, cut to 2,000 chars, newest 5,000 per run kept, all pruned after
  14 days (`servers:prune-logs`, daily): Minecraft logs player names and IPs, admins only.
  `abandon()` (boot timeout and failed setup) pulls `RunLog::fallbackCommand()` (journal 200
  lines, systemctl status, cloud-init tail; 25 s) before deleting, then appends
  `RunLog::diagnose()` to the error as "Likely cause: …" (EULA, Java too old with the version
  from the class-file numbers, memory, missing jar, start command not runnable, port, Java not
  installed). It reads only from the last `systemd[1]: Started minecraft-…` line, so an earlier
  attempt's error doesn't stick. Crash loops (#5376): `advance()` gives up on a set-up starting
  run once systemd has restarted Minecraft `minecraft.crash_loop_restarts` (3) times, read from
  the newest "Scheduled restart job, restart counter is at N" line the shipper sent; with no
  `minecraft` lines 90 s after setup it asks `CloudInit::restartsCommand()` (`systemctl show -p
  NRestarts --value`) over SSH, at most once a minute (`mvm:restart-check:<run>` cache key).
  Then `abandon(..., 'crash_loop')` (fallback log + Likely cause), one alert. Admin → Servers → Log (`ServerLogController`): the last 10
  runs, the newest 1,000 lines, polls `/admin/servers/{slug}/log/lines?run=&after=` every 3 s
  while the run is active.
  Checked for real (Nov 22) with a systemd container (`ubuntu:24.04` + systemd, `--privileged
  --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw`, real journald, cloud-init's own unit and
  start script, a stub `java` that checks eula.txt like Minecraft) posting to the app under
  `artisan serve` in `php:8.4-cli` on the same network: EULA lines arrived and were diagnosed,
  `./run.sh` went 644 → 755, with the box ticked eula.txt was written and "Done" arrived, the
  restarted shipper resumed from its cursor, it exited on 410, no token on any command line.
- EULA (#5367, from #5351's criteria): `servers.eula_accepted_at/by`, the "I accept the
  Minecraft EULA for this server" box in the server form (ticking again keeps the first
  acceptance, unticking clears it). Ticked, prepare.sh writes `eula.txt` with `eula=true`
  before every start; unticked, it leaves the folder's own eula.txt alone. prepare.sh also
  `chmod 0755`s top-level `*.sh` files, since the default start command is `./run.sh` and zips
  made on Windows lose the executable bit.
- Software installs (#5351, `App\Minecraft`): `servers.software` (`Software` enum: custom,
  vanilla, paper, fabric; default custom, so every older server and a form without the field
  is custom) and `servers.minecraft_version`. `VersionSource`: `OnlineVersions` (Mojang's
  version manifest + per-version JSON for the server jar's SHA-1 and `javaVersion`; PaperMC's
  Fill API v3 `/versions` with the minimum Java, `/versions/{v}/builds` newest first, only
  STABLE; Fabric meta for stable game/loader/installer, the installer's `.sha256` from Fabric's
  Maven), lists cached an hour, a version's JSON a day, as arrays. Releases from 1.17 on only
  (Mojang's list is cut there; Paper by Java ≥ 16 and no `-rc`/`-pre`; Fabric = its stable list
  ∩ Mojang's). `FakeVersions` in testing/e2e (26.3, 26.2, 1.21.11; 26.x = Java 25;
  `failWith()`/`reset()` in the cache). `ServerRequest::resolveInstall()` checks the version
  is listed, maps the needed Java to the oldest offered one that's enough
  (`Installer::javaFor`, null = refused) and writes `start_command` from the type's RAM
  (`Installer::heapMb`: RAM minus max(1 GB, 20 %), 256 MB steps, ≥ 1 GB; `server.jar`, or
  `fabric-server-launch.jar` for Fabric); the form's own start command/Java are excluded for
  installed servers (`exclude_unless:software,custom`). Use `serverAttributes()`, not `safe()`.
  The install runs in `ServerLauncher::prepare()` only when the server has **no current
  archive**: `plan()` is resolved then (newest stable Paper build / Fabric loader), and a
  `VersionSourceException` is logged on the run and retried on the next check (boot timeout
  still applies). `WorldSync::prepareFiles(..., $install)` refuses an archive and a plan
  together. prepare.sh `get`s each `Download` (https, hosts piston-data.mojang.com,
  fill-data.papermc.io, maven.fabricmc.net only; URL/file/hash pattern-checked) with curl,
  compares `sha1sum`/`sha256sum` (exit 24, file removed), Fabric then runs
  `java -jar fabric-installer.jar server -mcversion V -loader L -dir $DIR` (it downloads the
  loader libraries from Fabric's Maven itself; exit 25 without `fabric-server-launch.jar`).
  The first stop saves the folder, so nothing reinstalls. Modpacks (#5352): see below. Checked for real (Nov 22) against
  the live APIs in an `ubuntu:24.04` container (openjdk-25-jre-headless from noble, stubbed
  systemctl/cloud-init, start script run as the minecraft user): Vanilla 26.3, Paper 26.3
  build 258 and Fabric 26.3 (loader 0.20.1) each reached "Done" and answered
  `TcpMinecraftProbe` from a `php:8.4-cli` container on the same network; a wrong SHA-1 was
  refused (exit 24, no jar left). E2E: `software.spec` (`software-admin@example.com`); older
  specs that set a Java version pick Software → "My own start command" first.
- Modpacks (#5352, `App\Minecraft\Packs`, `App\Minecraft\Modrinth`): `Software::PackUrl`
  (`servers.pack_url`, https, `PackPlan::URL_PATTERN`, Java from the form) and
  `Software::Modrinth` (`modrinth_project_id` / `modrinth_version_id`, 8-char ids;
  `modrinth_title`; `minecraft_version` and Java from the version). `Software::versioned()` =
  Vanilla/Paper/Fabric (version lists); `installable()` includes the packs. Both packs get
  `start_command = sh mvm-launch.sh` and `servers.heap_mb` (`Installer::heapMb`).
  `ServerLauncher::prepare()` with no archive: `PackPlan::forUrl()` or
  `ModrinthSource::plan()` (`ModrinthException`: `permanent` → giveUp, else retry like a
  VersionSourceException). `OnlineModrinth` (API v2, no key, User-Agent names the app; search
  with facets `project_type:modpack` + `server_side:required|optional`, cached 10 min,
  version/title a day) downloads the `.mrpack` in the app, checks its SHA-512 and
  `ZipInspector`, and `ModrinthIndex::plan()` reads `modrinth.index.json`: files with
  `env.server = unsupported` skipped, each `PackFile` (path data only: printable, no
  backslash, ZipInspector-safe; hosts cdn.modrinth.com, github.com, raw.githubusercontent.com,
  gitlab.com; SHA-512) goes to the VPS as `/root/.mvm/mrfiles` lines `<sha512> <url> <path>`
  (deleted by the trap). `ModLoaders`: Fabric = the Fabric plan's downloads, Quilt = vanilla +
  meta.quiltmc.org's newest installer (SHA-256 inline), NeoForge / Forge = the Maven installer
    - its `.sha256`. Real Modrinth links are percent-encoded (`Download` allows `%`) and real
      paths have apostrophes. prepare.sh (`WorldSync::packScript`): `check_zip` (unzip -t, zipinfo
      for `/`, drive letters, `\`, `..`, symlinks; exit 27), pack URL → curl, size + SHA-256 in
      the log, unzip (single-folder rule), a top-level `neoforge-*/forge-*-installer.jar` runs with
      `--installServer` when there's no `libraries/`; Modrinth → mrpack, every file + sha512sum,
      `overrides/` then `server-overrides/`, the loader's installer. `launchScript()` writes
      `mvm-launch.sh` (`exec java @user_jvm_args.txt @<newest unix_args.txt> nogui`, else
      fabric/quilt launcher, else server.jar; exit 26). `heapScript()` on **every** start of a
      pack server (before `applyScript`, so the admin's user_jvm_args wins): drops `-Xms/-Xmx`
      lines, appends the type's, warns when the pack's `-Xmx` is bigger. prepare.sh now
      `chmod -R u+rwX "$DIR"` before the chown: a real .mrpack's overrides unpacked as `----------`.
      Timeouts: `pack_boot_timeout_minutes` 45 (Watchdog, pack server with no current archive),
      `pack_prepare_timeout` 2400 (PrepareServerRun job 2700). `FakeModrinth` in testing/e2e
      (Cobble Pack `AAAAAAAA` Fabric, Forge Frontier `BBBBBBBB` NeoForge; `failWith($m, $permanent)`).
      Admin JSON for the form: `GET /admin/modrinth/search?q=`, `/admin/modrinth/projects/{id}/versions`
      (`ModrinthPicker.vue`). E2E: `modpack.spec` (`modpack-admin@example.com`).
      `PackLaunchScriptTest` runs the launch + memory steps with real sh. Checked for real (Nov 23)
      in `ubuntu:24.04` with Java stubbed: Vanilla Perfected 1.0.3 from the live API (112 files,
      all SHA-512s, Fabric 0.19.3) and Shay's ATM10 ServerFiles-8.6.zip (468 mods, the NeoForge
      installer found, unix_args launch, the 8 GB warning); a `../` zip and a symlink zip refused.
      Then (Shay allowed real jars, #5456) in `eclipse-temurin:25-jre` + curl/unzip with real Java:
      the same install ran the real Fabric installer and `sh mvm-launch.sh` as the minecraft user
      reached "Done" (129 mods), Java was the process itself, SIGTERM saved and exited; ATM10's real
      NeoForge 21.1.266 installer wrote `unix_args.txt`. `su` drops the image's
      `/opt/java/openjdk/bin` from PATH: link java into /usr/bin (a real VPS has it there). ATM10
      itself wasn't started (8 GB).
- Server config (#5377, `App\Servers\ServerFiles` + `ServerConfigForm`): server.properties,
  whitelist.json, ops.json, run.sh and user_jvm_args.txt. `world_archives.files` (json) holds
  the world's own copies: `ServerFiles::fromZip()` on upload (top level or inside a single
  folder, ≤ 64 KB, UTF-8 only), and save.sh prints `mvmfile <name> <base64>` lines before
  `size=…` (both the SFTP/FTP and S3 save scripts) that `parseSaveOutput()` returns as `files`;
  archives from before #5377 have `null` (the form says so). The server keeps **only what
  differs from the world**: `servers.properties` (json key => value; the 12 `COMMON` keys
  have form fields, others go in the "Other settings" box as key=value), `whitelist` /
  `ops` (json, UUIDs from Mojang), `run_sh` / `user_jvm_args` (text). Equal to the world's
  value, an empty field, or a file put back with "Use the pack's file" → not stored, so it
  keeps following the world. Laravel's TrimStrings strips the trailing newline from the file
  textareas: `ServerConfigForm::file()` compares rtrimmed and adds the newline back. Turning
  the whitelist on with nobody on it is refused. `PlayerLookup`: `MojangPlayers` (public bulk
  endpoint `api.minecraftservices.com/minecraft/profile/lookup/bulk/byname`, 10 names a call,
  unknown names left out, found ones cached a day), `FakePlayers` in testing/e2e (every valid
  name exists except `nobody*`; `mojang_down` fails the lookup). Names are looked up only when
  the list changed. On start, `ServerFiles::overrideFiles()` goes over SFTP into
  `/root/.mvm/files` (never in the script) and prepare.sh's `applyScript()` (after the world /
  install, before the chmod + EULA step) merges server.properties with awk (a changed key
  replaced in place, new keys appended, comments and the rest kept) and copies the others
  over the world's; the cleanup trap deletes `$M/files`. Values are written Java-properties
  style (`\` doubled). The form fields are `v-model` on local state (`ServerConfigFields.vue`):
  with `:value` on a textarea, Vue rewrites it on every re-render and typed text was lost.
  Checked for real (Nov 23) in `ubuntu:24.04` with Vanilla 26.3: Minecraft started with the
  MOTD, whitelist (real Mojang UUIDs), ops level and seed from the app and kept them; the awk
  merge on a file with comments and `key = value` spacing; save.sh's report read back.
  E2E: `server-config.spec` (`config-admin@example.com`); the zip helper is `tests/e2e/zip.ts`.
  Minecraft only checks whitelist.json while `white-list=true` (fresh installs and most packs
  ship it false), so a whitelist saved with Whitelist left off lets everyone in (#5402: "it
  didn't apply"). The form warns while typing, and prepare.sh logs "Whitelist on: N players"
  or "… white-list isn't true …: anyone can join". `ServerFilesApplyScriptTest` runs the
  apply step with real `sh` in a temp folder (Git Bash's sh locally).
- DNS names (#5338, `App\Dns`): Admin → DNS (`DnsController`, `DnsSetting`, one row: domain,
  Cloudflare zone id, `api_token` encrypted + `$hidden`). Only `/zones/{id}/dns_records` is
  called, so an "Edit zone DNS" token for one zone is enough (no Zone Read for `/zones`).
  Saving runs `Dns::test()`: make and delete a TXT `_mvm-test-<rand>.<domain>`; Cloudflare
  appends its own zone name to a name outside the zone, so a different returned name means
  the zone id belongs to another domain. `servers.subdomain` (unique, `Server::SUBDOMAIN_PATTERN`,
  a full name inside the zone is cut down to the part before the domain, the app's own host
  is refused). `ServerLauncher` calls `Dns::point()` after provision (VPS has its IP) and
  again when the VPS is running, `Dns::release()` in `end()`; both are wrapped so nothing
  DNS-related can fail a start or stop. `point()`: a record with that name and no managed
  comment → refused (`server_runs.dns_error`, one alert, address stays the IP); a managed one
  from an ended run is replaced; one owned by another active run is left. Record comment
  `managed-by=minecraft-vps-manager mvm-run-id=<id>`; `server_runs.dns_record_id/zone_id/name`.
  `servers:sweep` → `Dns::deleteOrphans()` (managed records no active run owns, by id or run
  id). `ServerPresenter` shows `dns_name` as the address while the record exists, and
  `dns_error` to admins only. `CloudflareDns` (Laravel `Http::`, 10 s timeout, pages of 100,
  re-filters names/comments itself), `FakeDnsProvider` in testing/e2e or `CLOUDFLARE_FAKE`
  (records in the cache; `bad-token`, zone `ffff…` 404, zone `eeee…` = `other.example`;
  `seed()`, `records()`, `failWith()`). Admin → DNS lists the managed A records it finds.
  `app:doctor` WARNs (never FAILs) on DNS. Not checked against the real Cloudflare API.
- `App\Cloud` (#5281): `CloudProvider` interface (create/find/delete server, managed
  servers, create/delete/list managed SSH keys, server types, locations), `HetznerCloud`
  (Laravel `Http::`, token from `config('services.hetzner.*')`), `FakeCloudProvider` (state in the cache, so it survives
  `artisan serve`'s separate processes in E2E; `setStatus()` moves a fake VPS on). The binding
  in `AppServiceProvider` returns the fake in the `testing` and `e2e` environments whatever
  `HETZNER_FAKE` says, and `Tests\TestCase` calls `Http::preventStrayRequests()`.
  Every VPS gets `managed-by=minecraft-vps-manager` + `mvm-server-id=<id>`; `deleteServer()`
  refuses anything without the first; `deleteSshKey()` does the same for keys. Login is
  SSH-key only, with the run's own key (see SSH keys above). Hetzner key names are unique
  per project (`uniqueness_error`, 409; the fake enforces it too). Volumes aren't in the
  interface: worlds go over SFTP (#5288).

## Rules for this app

- Never call a real cloud provider API from tests, CI or local E2E: every provider call goes
  through an interface with a fake. Creating a server costs real money on Shay's account.
- The local `.env` (gitignored) still holds a 2022 Linode token and root password (Shay is
  revoking it, #5278). Don't print it, don't use it. Tests and E2E don't read it.
- Dev containers publish on `127.0.0.1` only (there's no compose file now; Sail was removed).
