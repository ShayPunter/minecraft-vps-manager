<?php

namespace Tests\Unit;

use App\Minecraft\Download;
use App\Minecraft\Installer;
use App\Minecraft\InstallPlan;
use App\Minecraft\Software;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    public function test_the_heap_leaves_room_for_the_os(): void
    {
        $this->assertSame(1024, Installer::heapMb(2));
        $this->assertSame(3072, Installer::heapMb(4));
        $this->assertSame(6400, Installer::heapMb(8));
        $this->assertSame(13056, Installer::heapMb(16));
        $this->assertSame(1024, Installer::heapMb(1), 'Never below 1 GB.');
    }

    public function test_the_start_command_runs_the_right_jar(): void
    {
        $this->assertSame('java -Xms3072M -Xmx3072M -jar server.jar nogui', Installer::startCommand(Software::Vanilla, 4));
        $this->assertSame('java -Xms3072M -Xmx3072M -jar server.jar nogui', Installer::startCommand(Software::Paper, 4));
        $this->assertSame('java -Xms3072M -Xmx3072M -jar fabric-server-launch.jar nogui', Installer::startCommand(Software::Fabric, 4));
    }

    public function test_java_is_the_oldest_offered_one_that_is_new_enough(): void
    {
        $this->assertSame(17, Installer::javaFor(16));
        $this->assertSame(21, Installer::javaFor(21));
        $this->assertSame(25, Installer::javaFor(22));
        $this->assertNull(Installer::javaFor(26));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badUrls(): array
    {
        return [
            'http' => ['http://piston-data.mojang.com/v1/objects/x/server.jar'],
            'other host' => ['https://evil.example/server.jar'],
            'lookalike host' => ['https://piston-data.mojang.com.evil.example/server.jar'],
            'quote' => ["https://piston-data.mojang.com/v1/o'x/server.jar"],
            'space' => ['https://piston-data.mojang.com/v1/o x/server.jar'],
            'query' => ['https://piston-data.mojang.com/server.jar?x=$(id)'],
            'dot dot' => ['https://piston-data.mojang.com/../server.jar'],
        ];
    }

    #[DataProvider('badUrls')]
    public function test_downloads_only_come_from_the_sources(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Download($url, 'server.jar', 'sha1', str_repeat('a', 40));
    }

    public function test_downloads_need_a_real_hash_and_a_plain_jar_name(): void
    {
        $url = 'https://piston-data.mojang.com/v1/objects/x/server.jar';

        foreach ([
            fn () => new Download($url, 'server.jar', 'sha1', str_repeat('a', 64)),
            fn () => new Download($url, 'server.jar', 'sha256', str_repeat('A', 64)),
            fn () => new Download($url, '../server.jar', 'sha1', str_repeat('a', 40)),
            fn () => new Download($url, 'server.sh', 'sha1', str_repeat('a', 40)),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("Case {$i} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_plan_refuses_odd_versions_and_a_fabric_plan_without_its_installer(): void
    {
        $jar = new Download('https://piston-data.mojang.com/v1/objects/x/server.jar', 'server.jar', 'sha1', str_repeat('a', 40));

        $this->assertSame('1.21.11', (new InstallPlan(Software::Vanilla, '1.21.11', 'Vanilla 1.21.11', [$jar]))->version);

        foreach ([
            fn () => new InstallPlan(Software::Vanilla, "1.21'; reboot", 'Vanilla', [$jar]),
            fn () => new InstallPlan(Software::Fabric, '1.21.11', 'Fabric', [$jar], fabricLoader: '0.20.1'),
            fn () => new InstallPlan(Software::Custom, '1.21.11', 'Custom', [$jar]),
            fn () => new InstallPlan(Software::Vanilla, '1.21.11', 'Vanilla', []),
        ] as $i => $make) {
            try {
                $make();
                $this->fail("Case {$i} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
