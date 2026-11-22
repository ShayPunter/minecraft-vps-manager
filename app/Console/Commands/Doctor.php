<?php

namespace App\Console\Commands;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Enums\Role;
use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Support\Heartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * Checks a Forge install is ready to start real servers (#5285): Hetzner token (one
 * read-only call), the SSH key pair, the queue worker, the scheduler, an admin and where
 * alerts go. Prints what's missing and how to fix it; never prints a secret. Exit 1 when
 * anything fails, so it can gate a deploy too.
 */
#[Signature('app:doctor {--wait=15 : Seconds to wait for the queue worker to run a test job}')]
#[Description('Check the Hetzner token, SSH key, queue worker, scheduler and alerts')]
class Doctor extends Command
{
    /** @var list<array{string, string, string}> */
    private array $rows = [];

    public function handle(CloudProvider $cloud): int
    {
        $this->checkAppUrl();
        $this->checkHetzner($cloud);
        $this->checkSshKeys();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkAdmins();
        $this->checkAlerts();

        $this->table(['', 'Check', 'Details'], $this->rows);

        $failed = count(array_filter($this->rows, fn (array $row): bool => $row[0] === 'FAIL'));

        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed. Fix them and run app:doctor again.");

            return self::FAILURE;
        }

        $this->components->info('Ready to start servers.');

        return self::SUCCESS;
    }

    private function row(string $status, string $check, string $details): void
    {
        $this->rows[] = [$status, $check, $details];
    }

    private function checkAppUrl(): void
    {
        $url = (string) config('app.url');

        str_starts_with($url, 'https://')
            ? $this->row('OK', 'APP_URL', $url)
            : $this->row('WARN', 'APP_URL', "{$url} isn't https. Set APP_URL=https://… in the Forge environment.");
    }

    private function checkHetzner(CloudProvider $cloud): void
    {
        $token = config('services.hetzner.token');

        if (! is_string($token) || $token === '') {
            $this->row('FAIL', 'Hetzner token', 'HETZNER_TOKEN is empty. Create a Read & Write API token in the Hetzner Cloud project (Security → API tokens).');

            return;
        }

        try {
            $count = count($cloud->locations());
            $this->row('OK', 'Hetzner token', "Works (read-only call: {$count} locations).");
        } catch (CloudException $e) {
            $this->row('FAIL', 'Hetzner token', "Hetzner refused it: {$e->getMessage()}");
        }
    }

    private function checkSshKeys(): void
    {
        $publicPath = (string) config('services.hetzner.ssh_public_key_path');
        $privatePath = (string) config('services.hetzner.ssh_private_key_path');
        $make = 'Make the pair with: mkdir -p storage/app/private/ssh && ssh-keygen -t ed25519 -N "" -C mvm -f storage/app/private/ssh/id_ed25519';

        if (! is_readable($publicPath) || ! is_readable($privatePath)) {
            $missing = ! is_readable($publicPath) ? $publicPath : $privatePath;
            $this->row('FAIL', 'SSH key', "Not readable: {$missing}. {$make}");

            return;
        }

        try {
            $private = PublicKeyLoader::load((string) file_get_contents($privatePath));
        } catch (Throwable) {
            $this->row('FAIL', 'SSH key', "{$privatePath} isn't a private key the app can read (it needs no passphrase). {$make}");

            return;
        }

        if (! $private instanceof PrivateKey) {
            $this->row('FAIL', 'SSH key', "{$privatePath} is a public key. HETZNER_SSH_PRIVATE_KEY must point at the private half.");

            return;
        }

        $fromPrivate = explode(' ', trim($private->getPublicKey()->toString('OpenSSH')))[1] ?? '';
        $fromFile = explode(' ', trim((string) file_get_contents($publicPath)))[1] ?? null;

        $fromPrivate !== '' && $fromPrivate === $fromFile
            ? $this->row('OK', 'SSH key', 'Private and public halves match.')
            : $this->row('FAIL', 'SSH key', "{$publicPath} isn't the public half of {$privatePath}.");
    }

    private function checkQueue(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->row('WARN', 'Queue worker', 'QUEUE_CONNECTION=sync: starts and stops run inside the web request and can time out. Use database and a Forge queue worker.');

            return;
        }

        $sent = now()->subSecond();
        QueueHeartbeat::dispatch();
        $deadline = microtime(true) + max(0, (int) $this->option('wait'));

        do {
            $last = Heartbeat::last(Heartbeat::QUEUE);

            if ($last !== null && $last->greaterThanOrEqualTo($sent)) {
                $this->row('OK', 'Queue worker', "Ran a test job on the {$connection} queue.");

                return;
            }

            usleep(500_000);
        } while (microtime(true) < $deadline);

        $this->row('FAIL', 'Queue worker', "No worker ran a test job on the {$connection} queue within {$this->option('wait')} s. Add a Forge queue worker (connection {$connection}, timeout 660).");
    }

    private function checkScheduler(): void
    {
        $last = Heartbeat::last(Heartbeat::SCHEDULER);

        if ($last !== null && $last->greaterThan(now()->subMinutes(3))) {
            $this->row('OK', 'Scheduler', "servers:watch ran {$last->diffForHumans()}.");

            return;
        }

        $this->row('FAIL', 'Scheduler', ($last === null ? "servers:watch hasn't run yet." : "servers:watch last ran {$last->diffForHumans()}.")
            .' Turn on the Laravel scheduler for this site in Forge (it runs schedule:run every minute).');
    }

    private function checkAdmins(): void
    {
        $admins = User::query()->where('role', Role::Admin)->count();

        $admins > 0
            ? $this->row('OK', 'Admin', "{$admins} admin account(s).")
            : $this->row('FAIL', 'Admin', 'No admin yet. Run: php artisan app:create-admin --name="…" --email=…');
    }

    private function checkAlerts(): void
    {
        $address = config('minecraft.alert_mail');
        $mailer = (string) config('mail.default');
        $discord = config('minecraft.alert_discord_webhook');
        $to = is_string($address) && $address !== '' ? $address : 'every admin';
        $extra = is_string($discord) && $discord !== '' ? ', and the Discord webhook' : '';

        in_array($mailer, ['log', 'array'], true)
            ? $this->row('WARN', 'Alerts', "MAIL_MAILER={$mailer}: alert mails only reach the log{$extra}. Set the MAIL_* values, or MVM_ALERT_DISCORD_WEBHOOK.")
            : $this->row('OK', 'Alerts', "Mail to {$to} ({$mailer}){$extra}.");
    }
}
