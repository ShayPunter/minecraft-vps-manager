<?php

namespace App\Servers;

use App\Models\Server;
use InvalidArgumentException;
use ZipArchive;

/**
 * The few files in a server folder that admins edit in the app (#5377): server.properties,
 * the whitelist and ops lists, and a modpack's run.sh / user_jvm_args.txt.
 *
 * The app keeps a copy of what the current saved world has (WorldArchive::$files): read
 * from an uploaded zip, or printed by save.sh on every stop. The server's own settings hold
 * only what an admin changed; prepare.sh writes those over the world's files before every
 * start (applyScript()), and anything left alone stays as the world has it.
 */
class ServerFiles
{
    public const FILES = ['server.properties', 'run.sh', 'user_jvm_args.txt', 'whitelist.json', 'ops.json'];

    /** Bigger files aren't kept: these are all a few KB. */
    public const MAX_BYTES = 65536;

    /** Where prepare.sh finds the admin's versions on the VPS. */
    public const DIR = WorldSync::DIR.'/files';

    // server.properties keys: lowercase words with dots or dashes (level-seed, query.port).
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9.-]{0,63}$/';

    // Java edition names: 3–16 letters, digits or underscores.
    public const PLAYER_PATTERN = '/^[A-Za-z0-9_]{3,16}$/';

    /**
     * The settings with their own field in the form. Everything else goes in the free box.
     */
    public const COMMON = [
        'motd', 'difficulty', 'gamemode', 'max-players', 'pvp', 'white-list', 'enforce-whitelist',
        'view-distance', 'simulation-distance', 'spawn-protection', 'allow-flight', 'hardcore',
    ];

    /**
     * The managed files found in a zip: at its top, or inside the single folder a zip holds
     * (prepare.sh unpacks that one from inside too).
     *
     * @return array<string, string>
     */
    public static function fromZip(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return [];
        }

        try {
            $prefix = self::singleFolder($zip);
            $files = [];

            foreach (self::FILES as $name) {
                $index = $zip->locateName($prefix.$name);

                if ($index === false) {
                    continue;
                }

                $stat = $zip->statIndex($index);

                if ($stat === false || $stat['size'] > self::MAX_BYTES) {
                    continue;
                }

                $contents = $zip->getFromIndex($index);

                if (is_string($contents) && mb_check_encoding($contents, 'UTF-8')) {
                    $files[$name] = $contents;
                }
            }

            return $files;
        } finally {
            $zip->close();
        }
    }

    private static function singleFolder(ZipArchive $zip): string
    {
        $tops = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $tops[explode('/', $name, 2)[0].(str_contains($name, '/') ? '/' : '')] = true;

            if (count($tops) > 1) {
                return '';
            }
        }

        $top = (string) array_key_first($tops);

        return str_ends_with($top, '/') ? $top : '';
    }

    /**
     * save.sh's "mvmfile <name> <base64>" lines (the files as the world was saved).
     *
     * @return array<string, string>
     */
    public static function fromSaveOutput(string $output): array
    {
        preg_match_all('/^mvmfile ([A-Za-z0-9._-]+) ([A-Za-z0-9+\/=]*)$/m', $output, $matches, PREG_SET_ORDER);
        $files = [];

        foreach ($matches as [, $name, $base64]) {
            $contents = base64_decode($base64, true);

            if (in_array($name, self::FILES, true) && is_string($contents) && strlen($contents) <= self::MAX_BYTES && mb_check_encoding($contents, 'UTF-8')) {
                $files[$name] = $contents;
            }
        }

        return $files;
    }

    /** The output without the mvmfile lines, for the run's log. */
    public static function withoutFileLines(string $output): string
    {
        return (string) preg_replace('/^mvmfile .*\R?/m', '', $output);
    }

    /**
     * server.properties as Minecraft reads it (Java properties): comments and blank lines
     * skipped, the first "=" or ":" splits, backslash escapes undone.
     *
     * @return array<string, string>
     */
    public static function parseProperties(string $contents): array
    {
        $properties = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = ltrim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            if (preg_match('/^([^=:\s]+)\s*[=:]\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $properties[$m[1]] = self::unescape($m[2]);
        }

        return $properties;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/', function (array $m): string {
            $c = $m[1];

            if (strlen($c) === 5) {
                return mb_chr((int) hexdec(substr($c, 1)), 'UTF-8');
            }

            return match ($c) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                'f' => "\f",
                default => $c,
            };
        }, $value);
    }

    /** A value as a properties line needs it: backslashes doubled, leading spaces kept. */
    public static function escape(string $value): string
    {
        $escaped = str_replace('\\', '\\\\', $value);

        return str_starts_with($escaped, ' ') ? '\\'.$escaped : $escaped;
    }

    /**
     * Player names from whitelist.json / ops.json (ops keep their level).
     *
     * @return list<array{uuid: string, name: string, level?: int}>
     */
    public static function parsePlayers(string $json): array
    {
        $list = json_decode($json, true);

        if (! is_array($list)) {
            return [];
        }

        $players = [];

        foreach ($list as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['uuid'] ?? null)) {
                $player = ['uuid' => $entry['uuid'], 'name' => $entry['name']];

                if (is_int($entry['level'] ?? null)) {
                    $player['level'] = $entry['level'];
                }

                $players[] = $player;
            }
        }

        return $players;
    }

    /**
     * The admin's versions, as files for /root/.mvm/files (never inline in a script).
     *
     * @return array<string, array{0: string, 1: int}> remote path => [contents, mode]
     */
    public static function overrideFiles(Server $server): array
    {
        $files = [];

        if ($server->properties !== null && $server->properties !== []) {
            $lines = [];

            foreach ($server->properties as $key => $value) {
                if (preg_match(self::KEY_PATTERN, (string) $key) !== 1 || preg_match('/[\r\n\0]/', (string) $value) === 1) {
                    throw new InvalidArgumentException("Refusing server.properties entry [{$key}].");
                }

                $lines[] = $key.'='.self::escape((string) $value);
            }

            $files[self::DIR.'/server.properties'] = [implode("\n", $lines)."\n", 0600];
        }

        foreach (['whitelist' => 'whitelist.json', 'ops' => 'ops.json'] as $attribute => $name) {
            if ($server->{$attribute} !== null) {
                $files[self::DIR.'/'.$name] = [self::playersJson($server->{$attribute}, $attribute === 'ops'), 0600];
            }
        }

        foreach (['run_sh' => 'run.sh', 'user_jvm_args' => 'user_jvm_args.txt'] as $attribute => $name) {
            if ($server->{$attribute} !== null) {
                $files[self::DIR.'/'.$name] = [str_replace("\r\n", "\n", $server->{$attribute}), 0600];
            }
        }

        return $files;
    }

    /**
     * @param  list<array{uuid: string, name: string, level?: int}>  $players
     */
    private static function playersJson(array $players, bool $ops): string
    {
        $entries = array_map(fn (array $p): array => $ops
            ? ['uuid' => $p['uuid'], 'name' => $p['name'], 'level' => $p['level'] ?? 4, 'bypassesPlayerLimit' => false]
            : ['uuid' => $p['uuid'], 'name' => $p['name']], $players);

        return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * prepare.sh's step, after the world is in place: merge the admin's server.properties
     * lines into the world's (same key replaced, new keys added at the end, the rest kept)
     * and copy the other files over the world's.
     */
    public static function applyScript(): string
    {
        return <<<'SH'
            # The admin's changes from the app (#5377), over the world's own files.
            F="$M/files"
            if [ -f "$F/server.properties" ]; then
                touch "$DIR/server.properties"
                awk 'NR == FNR {
                        i = index($0, "="); if (i < 2) next
                        k = substr($0, 1, i - 1); v[k] = substr($0, i + 1); order[++n] = k; next
                    }
                    {
                        line = $0; sub(/^[ \t]+/, "", line)
                        if (line !~ /^[#!]/ && match(line, /^[^=: \t]+/)) {
                            k = substr(line, 1, RLENGTH)
                            if (k in v) { if (!(k in done)) print k "=" v[k]; done[k] = 1; next }
                        }
                        print $0
                    }
                    END { for (j = 1; j <= n; j++) if (!(order[j] in done)) print order[j] "=" v[order[j]] }' \
                    "$F/server.properties" "$DIR/server.properties" > "$M/work/server.properties"
                cat "$M/work/server.properties" > "$DIR/server.properties"
                echo "Applied server.properties changes from the app."
            fi
            for name in whitelist.json ops.json run.sh user_jvm_args.txt; do
                if [ -f "$F/$name" ]; then
                    cat "$F/$name" > "$DIR/$name"
                    echo "Wrote $name from the app."
                fi
            done

            SH;
    }
}
