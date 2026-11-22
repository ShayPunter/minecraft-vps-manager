<?php

namespace App\Servers;

use App\Models\Server;
use App\Models\ServerRun;
use App\Models\ServerRunLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A run's log (#5367): what the VPS did and what Minecraft printed, kept after the VPS is
 * deleted so a start that never came online can be understood.
 *
 *   - The log shipper on the VPS (a root systemd unit prepare.sh starts) posts the setup
 *     output once and then Minecraft's journal every few seconds to POST /runs/{id}/log,
 *     with a token that only appends to that run's log.
 *   - prepare.sh's own output is added by the app after each attempt.
 *   - Before a VPS that never came online is deleted, the app pulls the last journal lines
 *     over SSH (source "fallback"), in case the shipper never got going.
 *
 * Each run keeps its newest MAX_LINES lines; everything goes after RETENTION_DAYS.
 */
class RunLog
{
    public const MAX_LINES = 5000;

    public const MAX_LINE_LENGTH = 2000;

    public const MAX_BATCH_BYTES = 512 * 1024;

    public const MAX_BATCH_LINES = 2000;

    public const RETENTION_DAYS = 14;

    public const DIR = '/root/.mvm-log';

    public const SHIPPER_UNIT = 'mvm-log-ship.service';

    /**
     * A new token for the run's shipper: only its hash is stored, the token goes to the VPS.
     */
    public function issueToken(ServerRun $run): string
    {
        $token = Str::random(48);
        $run->log_token_hash = hash('sha256', $token);
        $run->save();

        return $token;
    }

    public function tokenMatches(ServerRun $run, string $token): bool
    {
        return $run->log_token_hash !== null && $token !== '' && hash_equals($run->log_token_hash, hash('sha256', $token));
    }

    /**
     * Add lines (CR stripped, empty ones skipped, each cut to MAX_LINE_LENGTH), then drop
     * the oldest past MAX_LINES.
     *
     * @return int how many lines were kept
     */
    public function append(ServerRun $run, string $source, string $text): int
    {
        if (! in_array($source, ServerRunLog::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown log source [{$source}].");
        }

        $lines = array_values(array_filter(
            array_map(fn (string $line): string => rtrim($line, "\r"), preg_split('/\n/', $text) ?: []),
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return 0;
        }

        $lines = array_slice($lines, -self::MAX_BATCH_LINES);
        $now = now();
        $rows = array_map(fn (string $line): array => [
            'server_run_id' => $run->id,
            'source' => $source,
            // Not valid UTF-8 (a mod printing raw bytes) would fail the insert: replace it.
            'line' => mb_substr(mb_convert_encoding($line, 'UTF-8', 'UTF-8'), 0, self::MAX_LINE_LENGTH),
            'created_at' => $now,
        ], $lines);

        foreach (array_chunk($rows, 500) as $chunk) {
            ServerRunLog::query()->insert($chunk);
        }

        $this->trim($run);

        return count($rows);
    }

    private function trim(ServerRun $run): void
    {
        $cutoff = ServerRunLog::query()
            ->where('server_run_id', $run->id)
            ->orderByDesc('id')
            ->skip(self::MAX_LINES)
            ->value('id');

        if ($cutoff !== null) {
            ServerRunLog::query()->where('server_run_id', $run->id)->where('id', '<=', $cutoff)->delete();
        }
    }

    /**
     * @return Collection<int, ServerRunLog>
     */
    public function after(ServerRun $run, int $afterId, int $limit = 1000): Collection
    {
        return ServerRunLog::query()
            ->where('server_run_id', $run->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'source', 'line', 'created_at']);
    }

    public function prune(): int
    {
        return ServerRunLog::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    /**
     * The usual reasons a server never comes online, read from its log. Null when none match.
     */
    public function diagnose(ServerRun $run): ?string
    {
        $lines = array_values(ServerRunLog::query()
            ->where('server_run_id', $run->id)
            ->orderBy('id')
            ->get(['line'])
            ->map(fn (ServerRunLog $log): string => $log->line)
            ->all());

        return self::explain(implode("\n", self::sinceLastStart($lines)));
    }

    /**
     * Only what Minecraft printed since systemd last started it: an earlier attempt's error
     * (an EULA refusal before the box was ticked) isn't why the latest one failed. A log
     * with no start line (setup failed) is read whole.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function sinceLastStart(array $lines): array
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/systemd\[1\]: Started minecraft-[a-z0-9-]+\.service/', $lines[$i]) === 1) {
                return array_slice($lines, $i);
            }
        }

        return $lines;
    }

    public static function explain(string $log): ?string
    {
        if (preg_match('/You need to agree to the EULA|eula\.txt.*(eula=false|agree)/i', $log) === 1) {
            return 'Minecraft stopped because the EULA wasn\'t accepted: tick "I accept the Minecraft EULA" in the server\'s settings.';
        }

        if (preg_match('/class file version (\d+)(?:\.\d+)?\).{0,200}?up to (\d+)/s', $log, $m) === 1) {
            $needed = (int) $m[1] - 44;
            $has = (int) $m[2] - 44;

            return "The server jar needs Java {$needed}, but the VPS has Java {$has}: set the server's Java version to {$needed}.";
        }

        if (preg_match('/UnsupportedClassVersionError|compiled by a more recent version of the Java Runtime/', $log) === 1) {
            return 'The server jar needs a newer Java than the VPS has: raise the server\'s Java version.';
        }

        if (preg_match('/Could not reserve enough space|Initial heap size set to a larger value|insufficient memory for the Java Runtime|Cannot allocate memory/', $log) === 1) {
            return 'Java couldn\'t get the memory the start command asks for (-Xms/-Xmx): lower it, or pick a server type with more RAM.';
        }

        if (preg_match('/Unable to access jarfile (\S+)/', $log, $m) === 1) {
            return "The start command's jar ({$m[1]}) isn't in the server folder: upload it in the world zip, or fix the command.";
        }

        if (preg_match('/mvm-start: .*(Permission denied|not found)/', $log, $m) === 1) {
            return "The start command couldn't run ({$m[1]}). For a script, use \"sh run.sh\" as the start command.";
        }

        if (preg_match('/FAILED TO BIND TO PORT|Address already in use/i', $log) === 1) {
            return 'Minecraft couldn\'t open its port: check server-port in server.properties.';
        }

        if (preg_match('/java isn\'t installed/', $log) === 1) {
            return 'Java didn\'t install on the VPS (its first-boot setup failed): see the setup lines in the log.';
        }

        return null;
    }

    /**
     * What the app runs over SSH before deleting a VPS that never came online.
     */
    public static function fallbackCommand(Server $server): string
    {
        $unit = CloudInit::unitName($server);

        return "journalctl -u {$unit} -n 200 -o short-iso --no-pager 2>&1; "
            .'echo "--- systemctl status ---"; systemctl status '.$unit.' --no-pager -n 0 2>&1; '
            .'echo "--- cloud-init (last 40 lines) ---"; tail -n 40 /var/log/cloud-init-output.log 2>&1; true';
    }

    /**
     * The shipper's files (put in DIR, root-only; prepare.sh installs and starts the unit).
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function shipperFiles(Server $server, ServerRun $run, string $token): array
    {
        if (preg_match(Server::SLUG_PATTERN, $server->slug) !== 1) {
            throw new InvalidArgumentException("Refusing a log shipper for slug [{$server->slug}].");
        }

        if (preg_match('/^[A-Za-z0-9]{32,128}$/', $token) !== 1) {
            throw new InvalidArgumentException('Refusing an odd log token.');
        }

        $url = rtrim((string) config('app.url'), '/').'/runs/'.$run->id.'/log';

        if (preg_match('/^https?:\/\/[A-Za-z0-9.:\[\]-]+\/runs\/\d+\/log$/', $url) !== 1) {
            throw new InvalidArgumentException('APP_URL must be a plain http(s) address for the log shipper.');
        }

        $unit = CloudInit::unitName($server);

        // curl reads the URL and the token from this file, so neither is ever on a command line.
        $curl = "url = \"{$url}\"\nheader = \"Authorization: Bearer {$token}\"\nheader = \"Content-Type: text/plain; charset=utf-8\"\n";

        $script = <<<'SH'
            #!/bin/sh
            # Minecraft VPS Manager (#5367): send the setup output once, then this server's
            # journal every few seconds, to the app. Root only; stops when the app says the
            # run is over (410) or the token is refused (403/404).
            set -u
            L=/root/.mvm-log
            SH
            ."\nUNIT='{$unit}'\n"
            .<<<'SH'
            post() {
                curl --config "$L/curl.conf" --silent --show-error --max-time 30 -o /dev/null -w '%{http_code}' \
                    -H "X-MVM-Source: $1" --data-binary "@$2" 2>/dev/null || echo 000
            }
            stop_if_done() { case "$1" in 410|403|404) exit 0 ;; esac; }
            if [ ! -f "$L/setup-sent" ] && [ -f /var/log/cloud-init-output.log ]; then
                tail -n 300 /var/log/cloud-init-output.log > "$L/setup.txt"
                code=$(post setup "$L/setup.txt")
                stop_if_done "$code"
                case "$code" in 2*) touch "$L/setup-sent" ;; esac
            fi
            while :; do
                if [ -s "$L/cursor" ]; then
                    journalctl -q -u "$UNIT" -o short-iso --no-pager --show-cursor --after-cursor "$(cat "$L/cursor")" -n 2000 > "$L/batch" 2>/dev/null
                else
                    journalctl -q -u "$UNIT" -o short-iso --no-pager --show-cursor -n 2000 > "$L/batch" 2>/dev/null
                fi
                cursor=$(sed -n 's/^-- cursor: //p' "$L/batch" | tail -n 1)
                sed -i '/^-- cursor: /d' "$L/batch"
                if [ -s "$L/batch" ]; then
                    code=$(post minecraft "$L/batch")
                    stop_if_done "$code"
                    case "$code" in 2*) [ -n "$cursor" ] && printf '%s' "$cursor" > "$L/cursor" ;; esac
                fi
                sleep 5
            done

            SH;

        $unitFile = <<<'UNIT'
            [Unit]
            Description=Minecraft VPS Manager log shipper
            After=network-online.target
            Wants=network-online.target

            [Service]
            ExecStart=/bin/sh /root/.mvm-log/ship.sh
            Restart=on-failure
            RestartSec=10

            [Install]
            WantedBy=multi-user.target

            UNIT;

        return [
            self::DIR.'/ship.sh' => [$script, 0700],
            self::DIR.'/curl.conf' => [$curl, 0600],
            self::DIR.'/'.self::SHIPPER_UNIT => [$unitFile, 0644],
        ];
    }
}
