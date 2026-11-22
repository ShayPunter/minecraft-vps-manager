<?php

namespace App\Dns;

final readonly class DnsRecord
{
    // Every record the app makes carries this at the start of its comment; the sweep only
    // ever deletes records that do, so records made by hand are never touched.
    public const MANAGED = 'managed-by=minecraft-vps-manager';

    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public string $content,
        public ?string $comment,
    ) {}

    public static function commentForRun(int $runId): string
    {
        return self::MANAGED." mvm-run-id={$runId}";
    }

    public function isManaged(): bool
    {
        return $this->comment !== null && str_starts_with($this->comment, self::MANAGED);
    }

    public function runId(): ?int
    {
        if (! $this->isManaged() || preg_match('/(?:^|\s)mvm-run-id=(\d+)(?:\s|$)/', (string) $this->comment, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
