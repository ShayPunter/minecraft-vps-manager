<?php

namespace App\Cloud;

/**
 * What to create. The provider adds the managed-by and server-id labels itself.
 */
final readonly class ServerSpec
{
    /**
     * @param  array<string, string>  $labels  extra labels
     */
    public function __construct(
        public string $name,
        public string $serverType,
        public string $location,
        public int $minecraftServerId,
        public string $image = 'ubuntu-24.04',
        public ?string $userData = null,
        public array $labels = [],
    ) {}

    /**
     * The labels the VPS gets: the extras, then the app's own (which always win).
     *
     * @return array<string, string>
     */
    public function allLabels(): array
    {
        return [
            ...$this->labels,
            CloudProvider::MANAGED_LABEL => CloudProvider::MANAGED_VALUE,
            CloudProvider::SERVER_ID_LABEL => (string) $this->minecraftServerId,
        ];
    }
}
