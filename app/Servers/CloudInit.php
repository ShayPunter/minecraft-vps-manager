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

    /**
     * @param  SshKeyPair|null  $hostKey  the VPS's SSH host key, made by the app for this run
     *                                    (#5527) so the first login can check it; sshd gets
     *                                    only this one (no other types generated)
     */
    public static function for(Server $server, ?SshKeyPair $hostKey = null): string
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
            // zip/unzip pack and unpack worlds (#5288, WorldSync); curl (FTP storage) and
            // the OpenSSH sftp client are in Hetzner's Ubuntu image already.
            'packages' => ["openjdk-{$server->java_version}-jre-headless", 'zip', 'unzip'],
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
                ['chown', '-R', 'minecraft:minecraft', self::BASE_DIR],
                // Not enabled or started here (#5288): the app logs in once this has finished,
                // puts the saved world in place and starts the unit (WorldSync's prepare.sh).
                // Not enabled at all, so a reboot can't start an empty world that a stop
                // would then save over the real one.
                ['systemctl', 'daemon-reload'],
            ],
        ];

        if ($hostKey !== null) {
            $config['ssh_deletekeys'] = true;
            $config['ssh_genkeytypes'] = [];
            $config['ssh_keys'] = [
                'ed25519_private' => $hostKey->privateKey,
                'ed25519_public' => $hostKey->publicKey,
            ];
        }

        return "#cloud-config\n".json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Run as root over SSH by the watchdog: stop Minecraft (saving the world), flush the disk,
     * and fail unless systemd says the stop was clean (not killed after TimeoutStopSec).
     */
    public static function stopCommand(Server $server): string
    {
        if (preg_match(Server::SLUG_PATTERN, $server->slug) !== 1) {
            throw new InvalidArgumentException("Refusing to build a stop command for slug [{$server->slug}].");
        }

        $unit = self::unitName($server);

        return 'systemctl stop '.$unit.'; r=$(systemctl show -p Result --value '.$unit.'); sync; echo "result=$r"; [ "$r" = success ]';
    }

    /**
     * How many times systemd has restarted Minecraft since the VPS booted (#5376), as a bare number.
     */
    public static function restartsCommand(Server $server): string
    {
        if (preg_match(Server::SLUG_PATTERN, $server->slug) !== 1) {
            throw new InvalidArgumentException("Refusing a restart check for slug [{$server->slug}].");
        }

        return 'systemctl show -p NRestarts --value '.self::unitName($server);
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
            # The watchdog stops the server with systemctl stop: SIGTERM runs Minecraft's
            # shutdown hook, which saves every world. Java then exits 143, which is a clean
            # stop. Big modpacks can take a while to save before systemd gives up and kills it.
            SuccessExitStatus=0 143
            TimeoutStopSec=300

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
