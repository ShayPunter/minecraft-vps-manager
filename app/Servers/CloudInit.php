<?php

namespace App\Servers;

use App\Models\Server;
use InvalidArgumentException;

/**
 * The cloud-init user data a server's VPS boots with: install Java, make a `minecraft`
 * user, and run the server under a systemd unit named after the slug.
 *
 * Written as JSON (valid YAML) so no value can break out of the document, file bodies are
 * base64, and commands are argv lists (cloud-init runs those without a shell). The only
 * thing a shell ever sees is the admin's start command, on purpose, inside the start
 * script on the server's own VPS. The slug is checked against Server::SLUG_PATTERN again here.
 */
class CloudInit
{
    public const BASE_DIR = '/opt/minecraft';

    public static function for(Server $server): string
    {
        if (preg_match(Server::SLUG_PATTERN, $server->slug) !== 1) {
            throw new InvalidArgumentException("Refusing to build cloud-init for slug [{$server->slug}].");
        }

        if (! in_array($server->java_version, config('minecraft.java_versions'), true)) {
            throw new InvalidArgumentException("Unsupported Java version [{$server->java_version}].");
        }

        $dir = self::BASE_DIR.'/'.$server->slug;
        $unit = self::unitName($server);

        $config = [
            'package_update' => true,
            'packages' => ["openjdk-{$server->java_version}-jre-headless"],
            'write_files' => [
                [
                    'path' => '/usr/local/bin/mvm-start',
                    'permissions' => '0755',
                    'encoding' => 'b64',
                    'content' => base64_encode(self::startScript($dir, $server->start_command)),
                ],
                [
                    'path' => "/etc/systemd/system/{$unit}",
                    'permissions' => '0644',
                    'encoding' => 'b64',
                    'content' => base64_encode(self::unit($server->slug, $dir)),
                ],
            ],
            'runcmd' => [
                ['useradd', '--system', '--home-dir', self::BASE_DIR, '--shell', '/usr/sbin/nologin', 'minecraft'],
                ['mkdir', '-p', $dir],
                // World storage (#5288) downloads the world into $dir here, before the unit starts.
                ['chown', '-R', 'minecraft:minecraft', self::BASE_DIR],
                ['systemctl', 'daemon-reload'],
                ['systemctl', 'enable', '--now', $unit],
            ],
        ];

        return "#cloud-config\n".json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public static function unitName(Server $server): string
    {
        return "minecraft-{$server->slug}.service";
    }

    private static function startScript(string $dir, string $startCommand): string
    {
        $command = str_replace(["\r", "\n"], ' ', $startCommand);

        // Single quotes by hand, not escapeshellarg(): that quotes for the OS PHP runs on
        // (double quotes on Windows), and this script runs on Linux. $dir is BASE_DIR plus a
        // slug that passed SLUG_PATTERN, so it holds no quote.
        return "#!/bin/sh\nset -e\ncd '{$dir}'\nexec {$command}\n";
    }

    private static function unit(string $slug, string $dir): string
    {
        return <<<UNIT
            [Unit]
            Description=Minecraft server {$slug}
            After=network-online.target
            Wants=network-online.target

            [Service]
            User=minecraft
            WorkingDirectory={$dir}
            ExecStart=/usr/local/bin/mvm-start
            Restart=on-failure
            RestartSec=10

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
