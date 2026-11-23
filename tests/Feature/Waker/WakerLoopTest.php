<?php

namespace Tests\Feature\Waker;

use App\Models\DnsSetting;
use App\Models\Server;
use App\Servers\TcpMinecraftProbe;
use App\Waker\Packets;
use App\Waker\WakerLoop;
use App\Waker\WakerReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * The waker over real sockets on 127.0.0.1 (#5620), driven a tick at a time in this process.
 */
class WakerLoopTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private WakerLoop $loop;

    private string $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        $row = new DnsSetting;
        $row->domain = 'example.com';
        $row->zone_id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $row->api_token = 'cf-token-123';
        $row->waker_ipv4 = '198.51.100.10';
        $row->save();

        Server::factory()->create(['name' => 'ATM9', 'slug' => 'atm9', 'subdomain' => 'atm9', 'wake_on_join' => true]);

        $this->loop = new WakerLoop($this->app->make(WakerReplies::class), maxPerIp: 3, timeoutSeconds: 1.0);
        $this->address = $this->loop->listen('127.0.0.1', 0);
    }

    protected function tearDown(): void
    {
        $this->loop->close();
        parent::tearDown();
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $client = stream_socket_client("tcp://{$this->address}", $code, $message, 2);
        $this->assertNotFalse($client, $message);
        stream_set_blocking($client, false);
        $this->loop->tick(0.05); // accept

        return $client;
    }

    /**
     * Send, then let the waker run until the other side hangs up (or 3 s pass); returns what came back.
     *
     * @param  resource  $client
     */
    private function exchange($client, string $bytes, bool $untilClosed = true): string
    {
        if ($bytes !== '') {
            fwrite($client, $bytes);
        }

        $received = '';
        $deadline = microtime(true) + 3;

        while (microtime(true) < $deadline) {
            $this->loop->tick(0.02);
            $chunk = fread($client, 65536);
            $received .= $chunk === false ? '' : $chunk;

            if (feof($client) || (! $untilClosed && $received !== '')) {
                break;
            }
        }

        return $received;
    }

    private static function jsonOf(string $packetBody): mixed
    {
        $offset = 0;

        return json_decode(Packets::readString($packetBody, $offset, 32767), true);
    }

    public function test_a_server_list_ping_over_tcp(): void
    {
        $client = $this->connect();

        // The same bytes the app's own probe sends to a real Minecraft server.
        $answer = $this->exchange($client, TcpMinecraftProbe::statusRequest('atm9.example.com', 25565), untilClosed: false);
        $packet = Packets::take($answer);
        $this->assertNotNull($packet);
        $this->assertSame(0x00, $packet[0]);
        $status = self::jsonOf($packet[1]);
        $this->assertSame('ATM9', $status['description']['text']);
        $this->assertSame('Asleep', $status['version']['name']);

        $pong = $this->exchange($client, Packets::packet(0x01, pack('J', 42)));
        $this->assertSame(Packets::packet(0x01, pack('J', 42)), $pong);
        $this->assertTrue(feof($client), 'The waker hangs up after the pong.');
        fclose($client);

        $this->assertFalse(Server::query()->sole()->activeRun()->exists());
    }

    public function test_a_join_over_tcp_starts_the_server_and_gets_the_message(): void
    {
        $client = $this->connect();

        $handshake = Packets::packet(0x00, Packets::varInt(773).Packets::string('atm9.example.com').pack('n', 25565).Packets::varInt(2));
        $answer = $this->exchange($client, $handshake.Packets::packet(0x00, Packets::string('Steve').random_bytes(16)));
        fclose($client);

        $packet = Packets::take($answer);
        $this->assertNotNull($packet);
        $this->assertSame(0x00, $packet[0]);
        $this->assertSame(['text' => 'Starting ATM9 for you! Join again in a few minutes.'], self::jsonOf($packet[1]));

        $run = Server::query()->sole()->activeRun()->sole();
        $this->assertSame('127.0.0.1', $run->started_from_ip);

        // The waker notices the hang-up on its next round.
        $this->loop->tick(0.05);
        $this->assertSame(0, $this->loop->connectionCount());
    }

    public function test_junk_is_hung_up_on(): void
    {
        $client = $this->connect();

        $this->assertSame('', $this->exchange($client, "GET / HTTP/1.1\r\nHost: atm9.example.com\r\n\r\n"));
        $this->assertTrue(feof($client));
        fclose($client);
        $this->assertSame(0, $this->loop->connectionCount());
    }

    public function test_silent_connections_time_out_and_one_address_gets_a_few_at_once(): void
    {
        $clients = [$this->connect(), $this->connect(), $this->connect()];
        $this->assertSame(3, $this->loop->connectionCount());

        // A fourth from the same address is closed at once.
        $fourth = $this->connect();
        $this->assertSame('', $this->exchange($fourth, ''));
        $this->assertTrue(feof($fourth));
        $this->assertSame(3, $this->loop->connectionCount());

        // The silent ones go after the timeout (1 s here; they were accepted a few ticks apart).
        $started = microtime(true);

        while ($this->loop->connectionCount() > 0 && microtime(true) - $started < 3) {
            $this->loop->tick(0.05);
        }

        $this->assertSame(0, $this->loop->connectionCount());
        $this->assertGreaterThanOrEqual(0.8, microtime(true) - $started);
        $this->assertSame('', (string) fread($clients[0], 10));
        $this->assertTrue(feof($clients[0]));

        foreach ([...$clients, $fourth] as $client) {
            fclose($client);
        }
    }
}
