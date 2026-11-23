<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Every response carries the security headers (#5533), error pages and the routes outside
 * the web group included.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
    }

    private function assertHeaders(TestResponse $response, string $referrer = 'strict-origin-when-cross-origin'): void
    {
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::CONTENT_SECURITY_POLICY);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', $referrer);
        $response->assertHeader('Permissions-Policy', SecurityHeaders::PERMISSIONS_POLICY);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_pages_errors_and_the_routes_outside_the_web_group_send_them(): void
    {
        $this->assertHeaders($this->get('/')->assertOk());
        $this->assertHeaders($this->get('/login')->assertOk());
        $this->assertHeaders($this->get('/no-such-page')->assertNotFound());
        $this->assertHeaders($this->get('/dashboard')->assertRedirect('/login'));
        $this->assertHeaders($this->get('/version')->assertOk());
        $this->assertHeaders($this->get('/up')->assertOk());
        $this->assertHeaders($this->call('POST', '/runs/999999/log', [], [], [], [], "x\n")->assertNotFound());
        $this->assertHeaders($this->post('/servers/no-such-server/start')->assertNotFound());
    }

    public function test_signed_in_pages_send_them(): void
    {
        $this->assertHeaders($this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertOk());
        $this->assertHeaders($this->get('/admin/servers')->assertOk());
    }

    public function test_the_share_page_keeps_its_own_referrer_policy(): void
    {
        $server = Server::factory()->linkOnly()->create();

        $response = $this->get('/s/'.$server->share_token)->assertOk();

        // no-referrer, so the token never leaves in a Referer.
        $this->assertHeaders($response, 'no-referrer');
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
