<?php

namespace App\Minecraft;

/**
 * The start command and Java version for a server the app installs (#5351). Both are
 * worked out when the server is saved and stored on it like a custom server's, so the
 * form shows what will run.
 */
class Installer
{
    /**
     * Modpacks (#5352): prepare.sh writes mvm-launch.sh when it installs the pack. It execs
     * Java with the pack's user_jvm_args.txt, where the memory flags go.
     */
    public const PACK_START_COMMAND = 'sh mvm-launch.sh';

    /**
     * Java heap for a VPS with this much RAM: leave the OS and Java's own overhead at least
     * 1 GB (a fifth of the RAM on bigger types), in steps of 256 MB, never below 1 GB.
     */
    public static function heapMb(float $memoryGb): int
    {
        $total = (int) floor($memoryGb * 1024);
        $reserve = max(1024, (int) floor($total * 0.2));

        return max(1024, intdiv(max(0, $total - $reserve), 256) * 256);
    }

    public static function startCommand(Software $software, float $memoryGb): string
    {
        $heap = self::heapMb($memoryGb);
        $jar = $software === Software::Fabric ? 'fabric-server-launch.jar' : 'server.jar';

        return "java -Xms{$heap}M -Xmx{$heap}M -jar {$jar} nogui";
    }

    /**
     * The Java this app installs for a version that needs at least $required: the oldest
     * offered one that's new enough, or null if none is.
     */
    public static function javaFor(int $required): ?int
    {
        $offered = config('minecraft.java_versions');
        sort($offered);

        foreach ($offered as $java) {
            if ($java >= $required) {
                return $java;
            }
        }

        return null;
    }
}
