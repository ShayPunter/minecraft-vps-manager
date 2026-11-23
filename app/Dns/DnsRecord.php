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

    /**
     * A stopped server's name pointing at the waker (#5620).
     */
    public static function commentForWaker(int $serverId): string
    {
        return self::MANAGED." mvm-waker-server={$serverId}";
    }

    public function isManaged(): bool
    {
        return $this->comment !== null && str_starts_with($this->comment, self::MANAGED);
    }

    public function runId(): ?int
    {
        return $this->label('mvm-run-id');
    }

    /**
     * The server this waker record is for, or null when it isn't one.
     */
    public function wakerServerId(): ?int
    {
        return $this->label('mvm-waker-server');
    }

    private function label(string $key): ?int
    {
        if (! $this->isManaged() || preg_match('/(?:^|\s)'.$key.'=(\d+)(?:\s|$)/', (string) $this->comment, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
