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

        $key = $fake->createSshKey('mvm-one', 'ssh-ed25519 AAAA mvm-one');
        $server = $fake->createServer(new ServerSpec('mvm-one', 'cx43', 'fsn1', 3, sshKeyIds: [$key->id], userData: 'cloud-init'));

        $this->assertSame('initializing', $server->status);
        $this->assertSame([$key->id], $fake->rawServer($server->id)['ssh_keys'] ?? null);
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

    public function test_a_server_needs_an_existing_ssh_key(): void
    {
        $fake = $this->fake();

        foreach ([[], [999]] as $keyIds) {
            try {
                $fake->createServer(new ServerSpec('mvm-one', 'cx43', 'fsn1', 3, sshKeyIds: $keyIds));
                $this->fail('Expected a CloudException');
            } catch (CloudException) {
                $this->assertSame([], $fake->managedServers());
            }
        }
    }

    public function test_it_creates_lists_and_deletes_ssh_keys_but_never_unmanaged_ones(): void
    {
        $fake = $this->fake();

        $key = $fake->createSshKey('mvm-a-1', "ssh-ed25519 AAAA mvm-a-1\n", [CloudProvider::RUN_ID_LABEL => '1']);
        $handMade = $fake->addUnmanagedSshKey('shay-laptop');

        $this->assertTrue($key->isManaged());
        $this->assertSame(1, $key->runId());
        $this->assertSame('ssh-ed25519 AAAA mvm-a-1', $fake->sshPublicKey($key->id));
        $this->assertSame([$key->id], array_map(fn ($k) => $k->id, $fake->managedSshKeys()));

        // Names are unique, as at Hetzner.
        try {
            $fake->createSshKey('mvm-a-1', 'ssh-ed25519 BBBB');
            $this->fail('Expected a CloudException');
        } catch (CloudException $e) {
            $this->assertSame('uniqueness_error', $e->errorCode);
        }

        $fake->deleteSshKey($key->id);
        $fake->deleteSshKey($key->id);
        $this->assertSame([], $fake->managedSshKeys());

        $this->expectException(CloudException::class);
        $fake->deleteSshKey($handMade->id);
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
