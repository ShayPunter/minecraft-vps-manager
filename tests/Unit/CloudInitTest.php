<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Servers\CloudInit;
use InvalidArgumentException;
use Tests\TestCase;

class CloudInitTest extends TestCase
{
    private function server(array $attributes = []): Server
    {
        return new Server([
            'name' => 'Vanilla "quoted" $(name)',
            'slug' => 'vanilla',
            'server_type' => 'cx33',
            'location' => 'fsn1',
            'start_command' => 'java -Xmx6G -jar server.jar nogui',
            'java_version' => 21,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $userData): array
    {
        $this->assertStringStartsWith("#cloud-config\n", $userData);

        return json_decode(substr($userData, strlen("#cloud-config\n")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_installs_java_and_runs_the_server_under_a_systemd_unit(): void
    {
        $config = $this->decode(CloudInit::for($this->server()));

        $this->assertSame(['openjdk-21-jre-headless', 'zip', 'unzip'], $config['packages']);
        $this->assertSame(['systemctl', 'daemon-reload'], end($config['runcmd']));
        $this->assertNotContains(['systemctl', 'enable', '--now', 'minecraft-vanilla.service'], $config['runcmd']);
        $this->assertContains(['mkdir', '-p', '/opt/minecraft/vanilla'], $config['runcmd']);

        $files = collect($config['write_files'])->keyBy('path');
        $start = base64_decode($files['/usr/local/bin/mvm-start']['content']);
        $this->assertSame("#!/bin/sh\nset -e\ncd '/opt/minecraft/vanilla'\nexec java -Xmx6G -jar server.jar nogui\n", $start);

        $unit = base64_decode($files['/etc/systemd/system/minecraft-vanilla.service']['content']);
        $this->assertStringContainsString("User=minecraft\n", $unit);
        $this->assertStringContainsString("WorkingDirectory=/opt/minecraft/vanilla\n", $unit);
        $this->assertStringContainsString("ExecStart=/usr/local/bin/mvm-start\n", $unit);
    }

    public function test_the_name_never_reaches_the_document(): void
    {
        $this->assertStringNotContainsString('quoted', CloudInit::for($this->server()));
    }

    public function test_it_refuses_an_unsafe_slug_even_if_validation_was_skipped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CloudInit::for($this->server(['slug' => 'x; reboot']));
    }

    public function test_it_refuses_an_unknown_java_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CloudInit::for($this->server(['java_version' => 8]));
    }

    public function test_a_newline_in_the_start_command_cant_add_a_second_line(): void
    {
        $config = $this->decode(CloudInit::for($this->server(['start_command' => "./run.sh\nreboot"])));
        $start = base64_decode(collect($config['write_files'])->firstWhere('path', '/usr/local/bin/mvm-start')['content']);

        $this->assertSame(4, substr_count($start, "\n"));
        $this->assertStringContainsString('exec ./run.sh reboot', $start);
    }
}
