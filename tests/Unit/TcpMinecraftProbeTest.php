<?php

namespace Tests\Unit;

use App\Servers\TcpMinecraftProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TcpMinecraftProbeTest extends TestCase
{
    /**
     * @return resource
     */
    private function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');
        $this->assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    private function response(string $json): string
    {
        $body = TcpMinecraftProbe::varInt(0x00).TcpMinecraftProbe::varInt(strlen($json)).$json;

        return TcpMinecraftProbe::varInt(strlen($body)).$body;
    }

    public function test_varints_match_the_protocol_examples(): void
    {
        $this->assertSame("\x00", TcpMinecraftProbe::varInt(0));
        $this->assertSame("\x7f", TcpMinecraftProbe::varInt(127));
        $this->assertSame("\x80\x01", TcpMinecraftProbe::varInt(128));
        $this->assertSame("\xdd\xc7\x01", TcpMinecraftProbe::varInt(25565));
        $this->assertSame("\xff\xff\xff\xff\x0f", TcpMinecraftProbe::varInt(-1));
    }

    public function test_the_status_request_is_a_handshake_then_an_empty_request(): void
    {
        $request = TcpMinecraftProbe::statusRequest('203.0.113.5', 25565);

        $handshake = "\x00\x2f\x0b203.0.113.5\x63\xdd\x01";
        $this->assertSame(chr(strlen($handshake)).$handshake."\x01\x00", $request);
    }

    public function test_it_reads_the_player_count(): void
    {
        $json = '{"version":{"name":"1.21.4","protocol":769},"players":{"max":20,"online":3},"description":{"text":"Hi"}}';

        $this->assertSame(3, TcpMinecraftProbe::readPlayers($this->stream($this->response($json))));
    }

    public function test_a_big_status_with_an_icon_is_read_in_full(): void
    {
        $json = json_encode(['players' => ['max' => 10, 'online' => 0], 'favicon' => 'data:image/png;base64,'.str_repeat('A', 40_000)]);

        $this->assertSame(0, TcpMinecraftProbe::readPlayers($this->stream($this->response((string) $json))));
    }

    public function test_a_status_without_players_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        TcpMinecraftProbe::readPlayers($this->stream($this->response('{"version":{}}')));
    }

    public function test_a_cut_off_answer_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        TcpMinecraftProbe::readPlayers($this->stream(substr($this->response('{"players":{"online":1}}'), 0, 10)));
    }
}
