<?php

namespace Tests\Feature\Servers;

use App\Minecraft\MojangPlayers;
use App\Minecraft\VersionSourceException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mojang's bulk name lookup (#5377), with faked answers shaped like the real one (checked
 * Nov 22, 2026: POST a JSON list, get [{id, name}], unknown names left out).
 */
class MojangPlayersTest extends TestCase
{
    public function test_names_come_back_with_their_own_spelling_and_a_dashed_uuid(): void
    {
        Http::fake([MojangPlayers::URL => Http::response([
            ['id' => '069a79f444e94726a5befca90e38aaf5', 'name' => 'Notch'],
        ])]);

        $found = (new MojangPlayers)->find(['notch', 'NobodyHere']);

        $this->assertSame(['notch' => ['uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5', 'name' => 'Notch']], $found);
        Http::assertSent(fn (Request $r): bool => $r->data() === ['notch', 'nobodyhere']);

        // Cached: no second call.
        Http::fake([MojangPlayers::URL => Http::response([], 500)]);
        $this->assertSame('Notch', (new MojangPlayers)->find(['NOTCH'])['notch']['name']);
    }

    public function test_more_than_ten_names_go_in_several_calls(): void
    {
        Http::fake([MojangPlayers::URL => Http::response([])]);

        (new MojangPlayers)->find(array_map(fn (int $i): string => "player{$i}", range(1, 23)));

        Http::assertSentCount(3);
    }

    public function test_mojang_being_down_is_an_error(): void
    {
        Http::fake([MojangPlayers::URL => Http::response('nope', 503)]);

        $this->expectException(VersionSourceException::class);
        $this->expectExceptionMessage("Couldn't reach Mojang to look up player names");

        (new MojangPlayers)->find(['Steve']);
    }
}
