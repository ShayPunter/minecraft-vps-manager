<?php

namespace Tests\Unit\Waker;

use App\Waker\Packets;
use App\Waker\ProtocolError;
use App\Waker\Replies;
use App\Waker\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The waker's protocol (#5620): handshake, status and login, as a real client sends them.
 */
class SessionTest extends TestCase
{
    /** @var list<array{string, string|null, string}> */
    public array $joins = [];

    /** @var list<string> */
    public array $pings = [];

    private function replies(): Replies
    {
        $test = $this;

        return new class($test) implements Replies
        {
            public function __construct(private readonly SessionTest $test) {}

            public function status(string $host): array
            {
                $this->test->pings[] = $host;

                return ['version' => ['name' => 'Asleep', 'protocol' => -1], 'description' => ['text' => "Hi {$host}"]];
            }

            public function join(string $host, ?string $player, string $ip): string
            {
                $this->test->joins[] = [$host, $player, $ip];

                return "Starting {$host} for {$player}";
            }
        };
    }

    private static function handshake(string $host, int $next, int $protocol = 773): string
    {
        return Packets::packet(0x00, Packets::varInt($protocol).Packets::string($host).pack('n', 25565).Packets::varInt($next));
    }

    /**
     * Login Start as 1.20.2+ sends it: the name, then the account's UUID.
     */
    private static function loginStart(string $name): string
    {
        return Packets::packet(0x00, Packets::string($name).random_bytes(16));
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    private static function packets(string $bytes): array
    {
        $packets = [];

        while (($packet = Packets::take($bytes)) !== null) {
            $packets[] = $packet;
        }

        return $packets;
    }

    private static function jsonOf(string $body): mixed
    {
        $offset = 0;

        return json_decode(Packets::readString($body, $offset, 32767), true);
    }

    public function test_a_status_ping_gets_the_entry_and_the_pong_and_never_starts_anything(): void
    {
        $session = new Session($this->replies(), '198.51.100.7');

        $out = $session->receive(self::handshake('atm9.example.com', 1).Packets::packet(0x00, ''));
        [[$id, $body]] = self::packets($out);
        $this->assertSame(0x00, $id);
        $this->assertSame(['version' => ['name' => 'Asleep', 'protocol' => -1], 'description' => ['text' => 'Hi atm9.example.com']], self::jsonOf($body));
        $this->assertFalse($session->done());

        $payload = pack('J', 1_700_000_000_123);
        $this->assertSame(Packets::packet(0x01, $payload), $session->receive(Packets::packet(0x01, $payload)));
        $this->assertTrue($session->done());
        $this->assertSame(['atm9.example.com'], $this->pings);
        $this->assertSame([], $this->joins);
    }

    public function test_bytes_can_arrive_one_at_a_time(): void
    {
        $session = new Session($this->replies(), '198.51.100.7');
        $out = '';

        foreach (str_split(self::handshake('atm9.example.com', 1).Packets::packet(0x00, '')) as $byte) {
            $out .= $session->receive($byte);
        }

        $this->assertCount(1, self::packets($out));
    }

    public function test_a_login_gets_the_join_answer_as_a_disconnect(): void
    {
        $session = new Session($this->replies(), '198.51.100.7');

        // A Forge client's address, with the root's dot, in capitals.
        $out = $session->receive(self::handshake("ATM9.Example.com.\0FML3\0", 2).self::loginStart('Steve'));

        [[$id, $body]] = self::packets($out);
        $this->assertSame(0x00, $id);
        $this->assertSame(['text' => 'Starting atm9.example.com for Steve'], self::jsonOf($body));
        $this->assertTrue($session->done());
        $this->assertSame([['atm9.example.com', 'Steve', '198.51.100.7']], $this->joins);
        $this->assertSame('atm9.example.com', $session->host());

        // Anything after the answer is ignored.
        $this->assertSame('', $session->receive(self::loginStart('Alex')));
        $this->assertCount(1, $this->joins);
    }

    public function test_a_transfer_is_a_login_and_an_unreadable_name_is_null(): void
    {
        $session = new Session($this->replies(), '198.51.100.7');
        $session->receive(self::handshake('atm9.example.com', 3).Packets::packet(0x00, Packets::varInt(200)));

        $this->assertSame([['atm9.example.com', null, '198.51.100.7']], $this->joins);
    }

    public function test_a_login_answer_waits_for_login_start(): void
    {
        $session = new Session($this->replies(), '198.51.100.7');

        $this->assertSame('', $session->receive(self::handshake('atm9.example.com', 2)));
        $this->assertSame([], $this->joins);
        $this->assertFalse($session->done());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function junk(): array
    {
        return [
            'legacy ping' => ["\xFE\x01\xFA"],
            'HTTP' => ["GET / HTTP/1.1\r\nHost: x\r\n\r\n"],
            'huge length' => [Packets::varInt(1_000_000).'x'],
            'zero length' => [Packets::varInt(0)],
            'VarInt too long' => ["\xFF\xFF\xFF\xFF\xFF\xFF"],
            'not a handshake' => [Packets::packet(0x05, 'abc')],
            'unknown next state' => [self::handshake('atm9.example.com', 7)],
            'a host too long' => [Packets::packet(0x00, Packets::varInt(773).Packets::string(str_repeat('a', 300)).pack('n', 25565).Packets::varInt(1))],
            'cut handshake' => [Packets::packet(0x00, Packets::varInt(773))],
            'a status ping before the request, with a bad payload' => [self::handshake('a.example.com', 1).Packets::packet(0x01, 'short')],
        ];
    }

    #[DataProvider('junk')]
    public function test_anything_else_is_refused(string $bytes): void
    {
        $session = new Session($this->replies(), '198.51.100.7');

        $this->expectException(ProtocolError::class);
        $session->receive($bytes);
    }

    public function test_too_much_waiting_data_is_refused(): void
    {
        $session = new Session($this->replies(), '198.51.100.7');

        // A packet that claims 2,000 bytes and never finishes, then more and more.
        $session->receive(Packets::varInt(2000)."\x00".str_repeat('a', 999));
        $this->expectException(ProtocolError::class);
        $session->receive(str_repeat('a', 4000));
    }

    public function test_varints_read_as_java_does(): void
    {
        foreach ([0, 1, 127, 128, 255, 25565, 2_147_483_647, -1, -2_147_483_648] as $value) {
            $this->assertSame([$value, strlen(Packets::varInt($value))], Packets::varIntAt(Packets::varInt($value), 0), "value {$value}");
        }

        $this->assertSame("\xFF\xFF\xFF\xFF\x0F", Packets::varInt(-1));
        $this->assertNull(Packets::varIntAt("\x80", 0));
    }
}
