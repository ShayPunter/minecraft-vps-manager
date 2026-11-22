<?php

namespace App\Cloud;

/**
 * A VPS as the provider reports it.
 */
final readonly class CloudServer
{
    /**
     * @param  string  $status  Hetzner's status: initializing, starting, running, stopping, off, deleting, …
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public ?string $ipv4,
        public array $labels,
    ) {}

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isManaged(): bool
    {
        return ($this->labels[CloudProvider::MANAGED_LABEL] ?? null) === CloudProvider::MANAGED_VALUE;
    }

    public function minecraftServerId(): ?int
    {
        $id = $this->labels[CloudProvider::SERVER_ID_LABEL] ?? null;

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }
}
