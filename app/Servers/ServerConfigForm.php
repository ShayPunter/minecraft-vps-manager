<?php

namespace App\Servers;

use App\Minecraft\PlayerLookup;
use App\Minecraft\VersionSourceException;
use App\Models\Server;
use Illuminate\Validation\Validator;

/**
 * The Properties, Players and Files part of the server form (#5377).
 *
 * The form starts from what the current saved world has, with the admin's earlier changes
 * on top. On save only what differs from the world is kept, so a setting left as it was
 * keeps following the world, and clearing a field gives the world's value back.
 */
class ServerConfigForm
{
    public const MAX_EXTRA_LINES = 50;

    /**
     * @return array<string, list<string>|string> validation rules for the form's fields
     */
    public static function rules(): array
    {
        $bool = ['nullable', 'in:true,false'];

        return [
            'props' => ['nullable', 'array'],
            'props.motd' => ['nullable', 'string', 'max:200', 'not_regex:/[\r\n\0]/'],
            'props.difficulty' => ['nullable', 'in:peaceful,easy,normal,hard'],
            'props.gamemode' => ['nullable', 'in:survival,creative,adventure,spectator'],
            'props.max-players' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'props.pvp' => $bool,
            'props.white-list' => $bool,
            'props.enforce-whitelist' => $bool,
            'props.view-distance' => ['nullable', 'integer', 'min:2', 'max:32'],
            'props.simulation-distance' => ['nullable', 'integer', 'min:2', 'max:32'],
            'props.spawn-protection' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'props.allow-flight' => $bool,
            'props.hardcore' => $bool,
            'extra_properties' => ['nullable', 'string', 'max:10000'],
            'whitelist_players' => ['nullable', 'string', 'max:5000'],
            'ops_players' => ['nullable', 'string', 'max:5000'],
            'run_sh' => ['nullable', 'string', 'max:'.ServerFiles::MAX_BYTES, 'not_regex:/\0/'],
            'user_jvm_args' => ['nullable', 'string', 'max:'.ServerFiles::MAX_BYTES, 'not_regex:/\0/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'props.motd.not_regex' => 'Keep the MOTD on one line.',
        ];
    }

    /**
     * What the form shows.
     *
     * @return array<string, mixed>
     */
    public static function props(?Server $server): array
    {
        $files = self::worldFiles($server);
        $world = ServerFiles::parseProperties($files['server.properties'] ?? '');
        $overrides = $server->properties ?? [];

        $values = [];

        foreach (ServerFiles::COMMON as $key) {
            $values[$key] = (string) ($overrides[$key] ?? $world[$key] ?? '');
        }

        $extra = [];

        foreach ($overrides as $key => $value) {
            if (! in_array($key, ServerFiles::COMMON, true)) {
                $extra[] = "{$key}={$value}";
            }
        }

        $worldOther = [];

        foreach ($world as $key => $value) {
            if (! in_array($key, ServerFiles::COMMON, true) && ! array_key_exists($key, $overrides)) {
                $worldOther[] = "{$key}={$value}";
            }
        }

        return [
            'values' => $values,
            'world' => array_map(fn (string $key): string => $world[$key] ?? '', array_combine(ServerFiles::COMMON, ServerFiles::COMMON)),
            'extra' => implode("\n", $extra),
            'world_other' => $worldOther,
            'whitelist' => self::namesText($server->whitelist ?? ServerFiles::parsePlayers($files['whitelist.json'] ?? '[]'), false),
            'ops' => self::namesText($server->ops ?? ServerFiles::parsePlayers($files['ops.json'] ?? '[]'), true),
            'run_sh' => $server->run_sh ?? $files['run.sh'] ?? '',
            'run_sh_pack' => $files['run.sh'] ?? null,
            'run_sh_changed' => $server?->run_sh !== null,
            'user_jvm_args' => $server->user_jvm_args ?? $files['user_jvm_args.txt'] ?? '',
            'user_jvm_args_pack' => $files['user_jvm_args.txt'] ?? null,
            'user_jvm_args_changed' => $server?->user_jvm_args !== null,
            'has_world_files' => is_array($server?->currentWorldArchive?->files),
            'has_world' => $server?->current_world_archive_id !== null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function worldFiles(?Server $server): array
    {
        $files = $server?->currentWorldArchive?->files;

        return is_array($files) ? $files : [];
    }

    /**
     * @param  list<array{uuid: string, name: string, level?: int}>  $players
     */
    private static function namesText(array $players, bool $levels): string
    {
        return implode("\n", array_map(fn (array $p): string => $levels ? $p['name'].' '.($p['level'] ?? 4) : $p['name'], $players));
    }

    /**
     * Work out what to save, adding errors to the validator. Returns null when it found errors.
     *
     * @param  array<string, mixed>  $input
     * @return array{properties: array<string, string>|null, whitelist: list<array{uuid: string, name: string}>|null, ops: list<array{uuid: string, name: string, level: int}>|null, run_sh: string|null, user_jvm_args: string|null}|null
     */
    public static function resolve(Validator $validator, array $input, ?Server $server): ?array
    {
        $errors = $validator->errors();

        foreach ($errors->keys() as $key) {
            if (array_key_exists(explode('.', $key)[0], self::rules())) {
                return null; // A field already failed its rule.
            }
        }

        $files = self::worldFiles($server);
        $world = ServerFiles::parseProperties($files['server.properties'] ?? '');
        $overrides = [];
        $props = is_array($input['props'] ?? null) ? $input['props'] : [];

        foreach (ServerFiles::COMMON as $key) {
            $value = trim((string) ($props[$key] ?? ''));

            if ($value !== '' && ($world[$key] ?? null) !== $value) {
                $overrides[$key] = $value;
            }
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($input['extra_properties'] ?? '')) ?: []), fn (string $l): bool => $l !== '' && $l[0] !== '#'));

        if (count($lines) > self::MAX_EXTRA_LINES) {
            $errors->add('extra_properties', 'Keep it to '.self::MAX_EXTRA_LINES.' lines.');
        }

        foreach ($lines as $i => $line) {
            $n = $i + 1;

            if (preg_match('/^([^=\s]+)\s*=\s*(.*)$/', $line, $m) !== 1 || preg_match(ServerFiles::KEY_PATTERN, $m[1]) !== 1) {
                $errors->add('extra_properties', "Line {$n} isn't a setting: write it as key=value (e.g. level-seed=12345).");

                continue;
            }

            if (in_array($m[1], ServerFiles::COMMON, true)) {
                $errors->add('extra_properties', "Set {$m[1]} with its own field above.");

                continue;
            }

            if (($world[$m[1]] ?? null) !== $m[2]) {
                $overrides[$m[1]] = $m[2];
            }
        }

        $whitelist = self::names((string) ($input['whitelist_players'] ?? ''), false, 'whitelist_players', $validator);
        $ops = self::names((string) ($input['ops_players'] ?? ''), true, 'ops_players', $validator);

        if ($whitelist === null || $ops === null || $errors->has('extra_properties')) {
            return null;
        }

        $whitelistOn = ($overrides['white-list'] ?? $world['white-list'] ?? 'false') === 'true';

        if ($whitelistOn && $whitelist === []) {
            $errors->add('whitelist_players', 'Add at least one player before turning the whitelist on, or nobody can join.');

            return null;
        }

        $whitelistChanged = self::changed($whitelist, ServerFiles::parsePlayers($files['whitelist.json'] ?? '[]'), false);
        $opsChanged = self::changed($ops, ServerFiles::parsePlayers($files['ops.json'] ?? '[]'), true);
        $accounts = [];
        $wanted = array_merge(
            $whitelistChanged ? array_column($whitelist, 'typed') : [],
            $opsChanged ? array_column($ops, 'typed') : [],
        );

        if ($wanted !== []) {
            try {
                $accounts = app(PlayerLookup::class)->find(array_values(array_unique($wanted)));
            } catch (VersionSourceException $e) {
                $errors->add($whitelistChanged ? 'whitelist_players' : 'ops_players', "{$e->getMessage()} Try again in a minute.");

                return null;
            }

            foreach (['whitelist_players' => $whitelistChanged ? $whitelist : [], 'ops_players' => $opsChanged ? $ops : []] as $field => $list) {
                $unknown = array_values(array_diff(array_keys($list), array_keys($accounts)));

                if ($unknown !== []) {
                    $errors->add($field, 'No Minecraft account is called '.implode(', ', array_map(fn (string $n): string => $list[$n]['typed'], $unknown)).'.');
                }
            }

            if ($errors->hasAny(['whitelist_players', 'ops_players'])) {
                return null;
            }
        }

        return [
            'properties' => $overrides === [] ? null : $overrides,
            'whitelist' => $whitelistChanged ? array_map(fn (string $n): array => $accounts[$n], array_keys($whitelist)) : null,
            'ops' => $opsChanged ? array_map(fn (string $n): array => [...$accounts[$n], 'level' => $ops[$n]['level']], array_keys($ops)) : null,
            'run_sh' => self::file($input['run_sh'] ?? null, $files['run.sh'] ?? null),
            'user_jvm_args' => self::file($input['user_jvm_args'] ?? null, $files['user_jvm_args.txt'] ?? null),
        ];
    }

    /**
     * One name per line or comma (ops: a level 1–4 after the name, default 4).
     *
     * @return array<string, array{typed: string, level: int}>|null lowercased name => what was typed; null on errors
     */
    private static function names(string $text, bool $levels, string $field, Validator $validator): ?array
    {
        $names = [];

        foreach (preg_split($levels ? '/[\r\n,]+/' : '/[\s,]+/', $text) ?: [] as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $entry) ?: [];
            $name = $parts[0];
            $level = 4;

            if ($levels && count($parts) > 1) {
                if (count($parts) > 2 || preg_match('/^[1-4]$/', $parts[1]) !== 1) {
                    $validator->errors()->add($field, "Write each op as a name, optionally followed by a level from 1 to 4 (e.g. Steve 4), not \"{$entry}\".");

                    return null;
                }

                $level = (int) $parts[1];
            }

            if (preg_match(ServerFiles::PLAYER_PATTERN, $name) !== 1) {
                $validator->errors()->add($field, "\"{$name}\" isn't a Minecraft name (3 to 16 letters, digits or underscores).");

                return null;
            }

            $names[strtolower($name)] = ['typed' => $name, 'level' => $level];
        }

        if (count($names) > 200) {
            $validator->errors()->add($field, 'Keep it to 200 players.');

            return null;
        }

        return $names;
    }

    /**
     * @param  array<string, array{typed: string, level: int}>  $names
     * @param  list<array{uuid: string, name: string, level?: int}>  $world
     */
    private static function changed(array $names, array $world, bool $levels): bool
    {
        $now = array_map(fn (array $n): string => $levels ? (string) $n['level'] : '', $names);
        $was = [];

        foreach ($world as $p) {
            $was[strtolower($p['name'])] = $levels ? (string) ($p['level'] ?? 4) : '';
        }

        ksort($now);
        ksort($was);

        return $now !== $was;
    }

    private static function file(mixed $text, ?string $pack): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        // Laravel trims submitted text, so compare without trailing whitespace and give the
        // file its final newline back.
        $text = rtrim(str_replace("\r\n", "\n", $text))."\n";

        return $pack !== null && $text === rtrim(str_replace("\r\n", "\n", $pack))."\n" ? null : $text;
    }
}
