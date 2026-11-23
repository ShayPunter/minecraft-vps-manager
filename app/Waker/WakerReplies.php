<?php

namespace App\Waker;

use App\Dns\Dns;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Servers\BootEstimate;
use App\Servers\ServerLauncher;
use App\Servers\StartRefused;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * What the waker tells players (#5620), from the server the typed address names.
 *
 * Server list: the server's name, then a line saying how it is ("Asleep. Join to start
 * it."), its icon, and the state ("Asleep", "Starting") where the player count usually is:
 * the version name is shown, in red, whenever its protocol isn't the client's, so it gets -1.
 *
 * Joining starts a stopped server that wakes on join. The admin turned that on for the server,
 * so the guest rules (daily budget, free slot) don't apply (#5818); the cap and this class's
 * per-IP limit do.
 */
class WakerReplies implements Replies
{
    /** Join attempts that may start a server, per IP per minute (like the website's Start). */
    public const STARTS_PER_MINUTE = 5;

    private const STARTING = [RunStatus::Provisioning, RunStatus::Booting, RunStatus::Starting];

    public function __construct(
        private readonly Dns $dns,
        private readonly ServerLauncher $launcher,
        private readonly Favicons $favicons,
    ) {}

    public function status(string $host): array
    {
        $server = $this->serverFor($host);

        if ($server === null) {
            return self::entry('No server here', 'No server has this address.', 'Nothing at this address');
        }

        $run = $server->activeRun()->first();

        $usual = $run === null ? $this->usualStart($server) : null;

        [$state, $line] = match (true) {
            $run === null => ['Asleep', $server->wake_on_join
                ? 'Asleep. Join to start it'.($usual !== null ? " (ready in {$usual})" : '').'.'
                : 'Asleep. Start it on the website.'],
            in_array($run->status, self::STARTING, true) => ['Starting', 'Starting: '.$this->timeLeft($server, $run)],
            $run->status === RunStatus::Online => ['Online', $this->ownRecord($run)
                ? "It's up! Refresh in a minute to see it."
                : 'Up at '.$this->ipAddress($run).'. Join that address.'],
            default => ['Stopping', 'Saving and stopping. Try again in a few minutes.'],
        };

        return self::entry($state, $line, $server->name, $this->favicons->get($server));
    }

    public function join(string $host, ?string $player, string $ip): string
    {
        $server = $this->serverFor($host);

        if ($server === null) {
            return 'There is no server at this address.';
        }

        $run = $server->activeRun()->first();

        if ($run !== null) {
            return match (true) {
                in_array($run->status, self::STARTING, true) => "{$server->name} is starting: ".$this->timeLeft($server, $run).' Join again then.',
                $run->status === RunStatus::Online => $this->ownRecord($run)
                    ? "{$server->name} is up! Your computer still has its old address: wait a minute and join again."
                    : "{$server->name} is up at ".$this->ipAddress($run).'. Join that address.',
                default => "{$server->name} is saving its world and stopping. Join again in a few minutes to start it.",
            };
        }

        if (! $server->wake_on_join) {
            return "{$server->name} is asleep. Start it on the website: ".config('app.url');
        }

        if (! RateLimiter::attempt("waker-start:{$ip}", self::STARTS_PER_MINUTE, fn (): bool => true, 60)) {
            return 'Too many tries. Wait a minute and join again.';
        }

        try {
            $this->launcher->start($server, null, $ip, byJoin: true);
        } catch (StartRefused $e) {
            return "{$server->name} can't start: {$e->getMessage()}";
        } catch (Throwable $e) {
            Log::error("Waker: start of {$server->slug} failed: {$e->getMessage()}");

            return "Something went wrong starting {$server->name}. Try the website: ".config('app.url');
        }

        // The name is what the client sent, not checked with Mojang.
        Log::info('Server started by a join', ['server' => $server->slug, 'player' => $player, 'ip' => $ip]);

        $usual = $this->usualStart($server);

        return $usual !== null
            ? "Starting {$server->name} for you! It's usually ready in {$usual}: join again then."
            : "Starting {$server->name} for you! Join again in a few minutes.";
    }

    /**
     * The server whose name the player typed: <subdomain>.<the DNS zone's domain>.
     */
    private function serverFor(string $host): ?Server
    {
        $domain = $this->dns->saved()?->domain;

        if ($domain === null || ! str_ends_with($host, '.'.$domain)) {
            return null;
        }

        $subdomain = substr($host, 0, -strlen('.'.$domain));

        return $subdomain === '' ? null : Server::query()->where('subdomain', $subdomain)->first();
    }

    /**
     * "about N min" for a start of this server (BootEstimate), or null without history.
     */
    private function usualStart(Server $server): ?string
    {
        $seconds = BootEstimate::typicalSeconds($server);

        return $seconds === null ? null : 'about '.self::minutes($seconds);
    }

    private function timeLeft(Server $server, ServerRun $run): string
    {
        $typical = BootEstimate::typicalSeconds($server);

        if ($typical === null || $run->created_at === null) {
            return 'it will be ready soon.';
        }

        $left = $typical - (int) $run->created_at->diffInSeconds(now());

        return $left > 0 ? 'about '.self::minutes($left).' left.' : 'any moment now.';
    }

    /**
     * Whether the server's name points at this run's VPS (else players need its IP).
     */
    private function ownRecord(ServerRun $run): bool
    {
        return $run->dns_record_id !== null;
    }

    private function ipAddress(ServerRun $run): string
    {
        $port = (int) config('minecraft.port');

        return $run->ipv4.($port === 25565 ? '' : ":{$port}");
    }

    private static function minutes(int $seconds): string
    {
        return max(1, (int) ceil($seconds / 60)).' min';
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(string $state, string $line, string $name, ?string $favicon = null): array
    {
        $entry = [
            'version' => ['name' => $state, 'protocol' => -1],
            'players' => ['max' => 0, 'online' => 0, 'sample' => []],
            'description' => [
                'text' => $name,
                'color' => 'white',
                'extra' => [['text' => "\n".$line, 'color' => 'gray']],
            ],
            'enforcesSecureChat' => false,
        ];

        if ($favicon !== null) {
            $entry['favicon'] = $favicon;
        }

        return $entry;
    }
}
