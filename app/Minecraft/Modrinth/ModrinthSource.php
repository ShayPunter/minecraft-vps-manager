<?php

namespace App\Minecraft\Modrinth;

use App\Minecraft\Packs\PackPlan;

/**
 * Modrinth modpacks (#5352): its public API needs no key. OnlineModrinth in production,
 * FakeModrinth in tests and E2E.
 */
interface ModrinthSource
{
    /**
     * Modpacks that run on a server, best match first.
     *
     * @return list<array{id: string, slug: string, title: string, description: string, icon_url: string|null, downloads: int}>
     *
     * @throws ModrinthException
     */
    public function search(string $query): array;

    /**
     * A modpack's name, as Modrinth shows it.
     *
     * @throws ModrinthException
     */
    public function title(string $projectId): string;

    /**
     * A project's versions this app can install, newest first.
     *
     * @return list<ModrinthVersion>
     *
     * @throws ModrinthException
     */
    public function versions(string $projectId): array;

    /**
     * @throws ModrinthException when it doesn't exist or isn't installable
     */
    public function version(string $versionId): ModrinthVersion;

    /**
     * Read the version's .mrpack (its index) into what prepare.sh installs.
     *
     * @throws ModrinthException
     */
    public function plan(string $versionId, string $title): PackPlan;
}
