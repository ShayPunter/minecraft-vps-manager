<?php

namespace App\Cloud;

/**
 * Where Minecraft VPSes are created and deleted. HetznerCloud talks to the real API;
 * FakeCloudProvider stands in for it in tests and E2E (a real server costs money).
 *
 * Every server the app creates carries the label `managed-by=minecraft-vps-manager`,
 * and nothing without it is ever deleted.
 */
interface CloudProvider
{
    public const MANAGED_LABEL = 'managed-by';

    public const MANAGED_VALUE = 'minecraft-vps-manager';

    /** Label holding the id of the app's Minecraft server a VPS belongs to. */
    public const SERVER_ID_LABEL = 'mvm-server-id';

    /**
     * Create and boot a VPS. Login is by the app's SSH key only: no root password.
     *
     * @throws CloudException
     */
    public function createServer(ServerSpec $spec): CloudServer;

    /**
     * The VPS with this id, or null when it no longer exists.
     *
     * @throws CloudException
     */
    public function findServer(int $id): ?CloudServer;

    /**
     * Delete a VPS the app created. Refuses (CloudException `not_managed`) one without
     * the managed-by label. A VPS that's already gone counts as deleted.
     *
     * @throws CloudException
     */
    public function deleteServer(int $id): void;

    /**
     * Every VPS with the managed-by label.
     *
     * @return list<CloudServer>
     *
     * @throws CloudException
     */
    public function managedServers(): array;

    /**
     * Server types on offer, for the admin form.
     *
     * @return list<ServerType>
     *
     * @throws CloudException
     */
    public function serverTypes(): array;

    /**
     * Locations, for the admin form.
     *
     * @return list<Location>
     *
     * @throws CloudException
     */
    public function locations(): array;
}
