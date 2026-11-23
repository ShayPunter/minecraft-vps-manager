<?php

namespace Tests\Unit;

use App\Servers\WorldSync;
use PHPUnit\Framework\TestCase;

/**
 * prepare.sh's modpack steps (#5352), run for real with `sh` in a temp folder: which start
 * line mvm-launch.sh gets, and the memory flags in user_jvm_args.txt.
 */
class PackLaunchScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $sh = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where sh 2>NUL' : 'command -v sh 2>/dev/null'));

        if ($sh === '') {
            $this->markTestSkipped('No sh here (CI runs it).');
        }

        $this->root = str_replace(DIRECTORY_SEPARATOR, '/', sys_get_temp_dir()).'/mvm-pack-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/m/work', 0777, true);
        mkdir($this->root.'/dir', 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->root);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files  path in the server folder => contents
     * @return array{0: int, 1: string}
     */
    private function runStep(string $step, array $files): array
    {
        foreach ($files as $path => $contents) {
            if (! is_dir(dirname("{$this->root}/dir/{$path}"))) {
                mkdir(dirname("{$this->root}/dir/{$path}"), 0777, true);
            }

            file_put_contents("{$this->root}/dir/{$path}", $contents);
        }

        $script = $this->root.'/step.sh';
        file_put_contents($script, "set -eu\nM='{$this->root}/m'\nDIR='{$this->root}/dir'\n".$step);

        $output = [];
        exec('sh '.escapeshellarg($script).' 2>&1', $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    private function launchLine(): string
    {
        $launch = (string) file_get_contents($this->root.'/dir/mvm-launch.sh');
        $this->assertStringStartsWith("#!/bin/sh\n", $launch);

        // Line 1 is the shebang, line 2 the comment.
        return explode("\n", $launch)[2] ?? '';
    }

    public function test_a_neoforge_install_starts_from_its_unix_args_never_the_packs_restart_loop(): void
    {
        [$exit, $output] = $this->runStep(WorldSync::launchScript(), [
            'libraries/net/neoforged/neoforge/21.1.266/unix_args.txt' => '-p libraries/x.jar',
            'startserver.sh' => "while true; do java -jar x; done\n",
        ]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('exec java @user_jvm_args.txt @libraries/net/neoforged/neoforge/21.1.266/unix_args.txt nogui "$@"', $this->launchLine());
        $this->assertStringContainsString('Starts with: java @user_jvm_args.txt @libraries/net/neoforged/neoforge/21.1.266/unix_args.txt nogui', $output);
    }

    public function test_forge_picks_the_newest_installed_version(): void
    {
        [$exit, $output] = $this->runStep(WorldSync::launchScript(), [
            'libraries/net/minecraftforge/forge/1.20.1-47.2.0/unix_args.txt' => 'old',
            'libraries/net/minecraftforge/forge/1.20.1-47.10.0/unix_args.txt' => 'new',
        ]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('@libraries/net/minecraftforge/forge/1.20.1-47.10.0/unix_args.txt', $this->launchLine());
    }

    public function test_fabric_quilt_and_plain_jars_are_found_in_that_order(): void
    {
        [$exit] = $this->runStep(WorldSync::launchScript(), ['quilt-server-launch.jar' => 'q', 'server.jar' => 's']);
        $this->assertSame(0, $exit);
        $this->assertSame('exec java @user_jvm_args.txt -jar quilt-server-launch.jar nogui "$@"', $this->launchLine());

        [$exit] = $this->runStep(WorldSync::launchScript(), ['fabric-server-launch.jar' => 'f']);
        $this->assertSame(0, $exit);
        $this->assertSame('exec java @user_jvm_args.txt -jar fabric-server-launch.jar nogui "$@"', $this->launchLine());
    }

    public function test_a_pack_with_nothing_to_start_fails_with_a_reason(): void
    {
        [$exit, $output] = $this->runStep(WorldSync::launchScript(), ['mods/a.jar' => 'a', 'startserver.sh' => 'x']);

        $this->assertSame(26, $exit);
        $this->assertStringContainsString("Couldn't find how to start this pack", $output);
        $this->assertFileDoesNotExist($this->root.'/dir/mvm-launch.sh');
    }

    public function test_the_memory_flags_replace_the_packs_own_and_warn_when_it_wants_more(): void
    {
        $atm10 = "# Xmx and Xms set the maximum and minimum RAM usage.\n-Xms4G\n-Xmx8G\n-XX:+UseG1GC\n-XX:MaxGCPauseMillis=200\n";

        [$exit, $output] = $this->runStep(WorldSync::heapScript(6400), ['user_jvm_args.txt' => $atm10]);

        $this->assertSame(0, $exit, $output);
        $this->assertSame(
            "# Xmx and Xms set the maximum and minimum RAM usage.\n-XX:+UseG1GC\n-XX:MaxGCPauseMillis=200\n-Xms6400M\n-Xmx6400M\n",
            file_get_contents($this->root.'/dir/user_jvm_args.txt'),
        );
        $this->assertStringContainsString('The pack asks for -Xmx8G, but this server type leaves Java 6400 MB', $output);
        $this->assertStringContainsString('Java memory: 6400 MB (from the server type).', $output);
    }

    public function test_enough_memory_gives_no_warning_and_a_missing_file_is_made(): void
    {
        [$exit, $output] = $this->runStep(WorldSync::heapScript(13056), ['user_jvm_args.txt' => "-Xmx8192M\n"]);
        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('asks for', $output);
        $this->assertSame("-Xms13056M\n-Xmx13056M\n", file_get_contents($this->root.'/dir/user_jvm_args.txt'));

        unlink($this->root.'/dir/user_jvm_args.txt');
        [$exit] = $this->runStep(WorldSync::heapScript(1024), []);
        $this->assertSame(0, $exit);
        $this->assertSame("-Xms1024M\n-Xmx1024M\n", file_get_contents($this->root.'/dir/user_jvm_args.txt'));
    }
}
