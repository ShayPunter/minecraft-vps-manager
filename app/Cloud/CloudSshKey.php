<?php

namespace App\Cloud;

/**
 * An SSH key registered with the provider.
 */
final readonly class CloudSshKey
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $labels,
    ) {}

    public function isManaged(): bool
    {
        return ($this->labels[CloudProvider::MANAGED_LABEL] ?? null) === CloudProvider::MANAGED_VALUE;
    }

    /** The run this key was made for (`mvm-run-id` label), if any. */
    public function runId(): ?int
    {
        $id = $this->labels[CloudProvider::RUN_ID_LABEL] ?? null;

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }
}
