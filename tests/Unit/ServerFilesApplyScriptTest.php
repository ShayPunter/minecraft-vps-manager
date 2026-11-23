<?php

namespace Tests\Unit;

use App\Servers\ServerFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * prepare.sh's apply step (#5377), run for real with `sh` in a temp folder: the app's files
 * land over the world's, and the log says whether Minecraft will check the whitelist (#5402:
 * a whitelist saved while white-list stays false lets everyone in, which looked like the
 * edit never applied).
 */
class ServerFilesApplyScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $sh = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where sh 2>NUL' : 'command -v sh 2>/dev/null'));

        if ($sh === '') {
            $this->markTestSkipped('No sh here (CI runs it).');
        }

        $this->root = str_replace(DIRECTORY_SEPARATOR, '/', sys_get_temp_dir()).'/mvm-apply-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/m/files', 0777, true);
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
     * @param  array<string, string>  $app  the app's files (/root/.mvm/files)
     * @param  array<string, string>  $world  the world's own files
     */
    private function apply(array $app, array $world): string
    {
        foreach ($app as $name => $contents) {
            file_put_contents("{$this->root}/m/files/{$name}", $contents);
        }

        foreach ($world as $name => $contents) {
            file_put_contents("{$this->root}/dir/{$name}", $contents);
        }

        mkdir($this->root.'/m/work');
        $script = $this->root.'/apply.sh';
        file_put_contents($script, "set -eu\nM='{$this->root}/m'\nDIR='{$this->root}/dir'\n".ServerFiles::applyScript());

        $output = [];
        exec('sh '.escapeshellarg($script).' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));

        return implode("\n", $output);
    }

    private function whitelist(int $players): string
    {
        $entries = [];

        for ($i = 0; $i < $players; $i++) {
            $entries[] = ['uuid' => sprintf('00000000-0000-0000-0000-%012d', $i), 'name' => "Player{$i}"];
        }

        return json_encode($entries, JSON_PRETTY_PRINT)."\n";
    }

    public function test_a_whitelist_with_white_list_on_says_so(): void
    {
        $out = $this->apply(
            ['whitelist.json' => $this->whitelist(2), 'server.properties' => "white-list=true\n"],
            ['server.properties' => "motd=Hi\nwhite-list=false\n"],
        );

        $this->assertStringContainsString('Wrote whitelist.json from the app.', $out);
        $this->assertStringContainsString('Whitelist on: 2 players in whitelist.json.', $out);
        $this->assertSame($this->whitelist(2), file_get_contents("{$this->root}/dir/whitelist.json"));
        $this->assertSame("motd=Hi\nwhite-list=true\n", file_get_contents("{$this->root}/dir/server.properties"));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function whitelistOff(): array
    {
        return [
            'the world has it off' => [['server.properties' => "white-list=false\n"]],
            'a fresh install (no server.properties yet)' => [[]],
        ];
    }

    #[DataProvider('whitelistOff')]
    public function test_a_whitelist_minecraft_wont_check_is_named_in_the_log(array $world): void
    {
        $out = $this->apply(['whitelist.json' => $this->whitelist(1)], $world);

        $this->assertStringContainsString('Wrote whitelist.json from the app.', $out);
        $this->assertStringContainsString("whitelist.json has 1 player, but white-list isn't true in server.properties: anyone can join.", $out);
    }

    public function test_spaced_properties_count_as_on(): void
    {
        $out = $this->apply([], ['whitelist.json' => $this->whitelist(3), 'server.properties' => "# comment\nwhite-list = true\n"]);

        $this->assertStringContainsString('Whitelist on: 3 players in whitelist.json.', $out);
    }

    public function test_an_empty_whitelist_says_nothing(): void
    {
        $out = $this->apply(['whitelist.json' => "[]\n"], ['server.properties' => "white-list=false\n"]);

        $this->assertStringNotContainsString('anyone can join', $out);
        $this->assertStringNotContainsString('Whitelist on', $out);
    }
}
