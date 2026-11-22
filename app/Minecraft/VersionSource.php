<?php

namespace App\Minecraft;

/**
 * Where Minecraft versions and server jars come from (#5351): Mojang's version manifest,
 * PaperMC's Fill API and Fabric's meta API (OnlineVersions), or a fake in tests and E2E.
 */
interface VersionSource
{
    /**
     * Release versions from 1.17 on (Java 17+), newest first. No snapshots or pre-releases.
     *
     * @return list<string>
     *
     * @throws VersionSourceException
     */
    public function versions(Software $software): array;

    /**
     * The Java major version this Minecraft version needs, as its source says.
     *
     * @throws VersionSourceException
     */
    public function javaFor(Software $software, string $version): int;

    /**
     * The files to download for a fresh install, each with its source's own hash. Resolved
     * when the install runs, so Paper and the Fabric loader get their newest stable build.
     *
     * @throws VersionSourceException
     */
    public function plan(Software $software, string $version): InstallPlan;
}
