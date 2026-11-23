<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * The site answers through Cloudflare (#5703): only Cloudflare's published ranges may say
 * who the visitor is (X-Forwarded-For, X-Forwarded-Proto). Anyone else's headers are ignored.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        Route::get('/_test/ip', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->secure(),
        ]));
    }

    /**
     * @param  array<string, string>  $server
     * @return array{ip: string, secure: bool}
     */
    private function seen(array $server): array
    {
        /** @var array{ip: string, secure: bool} */
        return $this->call('GET', '/_test/ip', [], [], [], $server)->assertOk()->json();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cloudflare(): array
    {
        return ['IPv4 edge' => ['162.158.1.20'], 'IPv6 edge' => ['2606:4700:10::ac43:1']];
    }

    #[DataProvider('cloudflare')]
    public function test_a_cloudflare_edge_says_who_the_visitor_is(string $edge): void
    {
        $this->assertSame(
            ['ip' => '203.0.113.7', 'secure' => true],
            $this->seen(['REMOTE_ADDR' => $edge, 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https']),
        );

        // An address the visitor sent in front of Cloudflare's own is not believed.
        $this->assertSame('203.0.113.7', $this->seen([
            'REMOTE_ADDR' => $edge,
            'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 203.0.113.7',
        ])['ip']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function others(): array
    {
        return ['the internet' => ['198.51.100.9'], 'this machine' => ['127.0.0.1'], 'IPv6' => ['2001:db8::1']];
    }

    #[DataProvider('others')]
    public function test_anyone_else_keeps_their_own_address(string $address): void
    {
        $this->assertSame(
            ['ip' => $address, 'secure' => false],
            $this->seen(['REMOTE_ADDR' => $address, 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https']),
        );
    }

    public function test_guests_behind_one_edge_get_their_own_start_limit_and_address(): void
    {
        Server::factory()->public()->create(['slug' => 'open-world']);
        $via = fn (string $visitor): array => ['REMOTE_ADDR' => '172.64.10.10', 'HTTP_X_FORWARDED_FOR' => $visitor];

        for ($i = 0; $i < 5; $i++) {
            $this->from('/')->post('/servers/open-world/start', [], $via('203.0.113.7'))->assertRedirect('/');
        }
        $this->from('/')->post('/servers/open-world/start', [], $via('203.0.113.7'))->assertTooManyRequests();

        // Another visitor on the same edge isn't in that count.
        $this->from('/')->post('/servers/open-world/start', [], $via('198.51.100.20'))->assertRedirect('/');

        $this->assertSame('203.0.113.7', ServerRun::query()->orderBy('id')->value('started_from_ip'));
    }
}
