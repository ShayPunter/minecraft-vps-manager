<?php

namespace Tests\Feature\Cloud;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Cloud\ServerSpec;
use Tests\TestCase;

class FakeCloudProviderTest extends TestCase
{
    private function fake(): FakeCloudProvider
    {
        $fake = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $fake);

        return $fake;
    }

    public function test_it_creates_finds_lists_and_deletes_servers(): void
    {
        $fake = $this->fake();

        $server = $fake->createServer(new ServerSpec('mvm-one', 'cx43', 'fsn1', 3, userData: 'cloud-init'));

        $this->assertSame('initializing', $server->status);
        $this->assertNotNull($server->ipv4);
        $this->assertSame(3, $server->minecraftServerId());
        $this->assertSame('cloud-init', $fake->rawServer($server->id)['user_data'] ?? null);

        $fake->setStatus($server->id, 'running');
        $this->assertTrue($fake->findServer($server->id)?->isRunning());
        $this->assertCount(1, $fake->managedServers());

        $fake->deleteServer($server->id);
        $this->assertNull($fake->findServer($server->id));
        $this->assertSame([], $fake->managedServers());

        // Deleting again is fine.
        $fake->deleteServer($server->id);
    }

    public function test_it_refuses_to_delete_servers_the_app_did_not_make(): void
    {
        $fake = $this->fake();
        $other = $fake->addUnmanagedServer('shays-own-box');

        $this->assertSame([], $fake->managedServers());

        $this->expectException(CloudException::class);
        $fake->deleteServer($other->id);
    }

    public function test_it_offers_types_and_locations(): void
    {
        $fake = $this->fake();

        $this->assertNotEmpty($fake->serverTypes());
        $this->assertSame(['fsn1', 'nbg1', 'hel1'], array_map(fn ($l) => $l->name, $fake->locations()));
    }
}
