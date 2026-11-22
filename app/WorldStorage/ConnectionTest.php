<?php

namespace App\WorldStorage;

final readonly class ConnectionTest
{
    private function __construct(
        public bool $ok,
        public string $message,
        public ?string $hostKey,
    ) {}

    public static function passed(?string $hostKey): self
    {
        $fingerprint = StorageSettings::fingerprint($hostKey);

        return new self(true, $fingerprint !== null
            ? "Connected, and the base folder is writable. Host key {$fingerprint}."
            : 'Connected, and the base folder is writable.', $hostKey);
    }

    public static function failed(string $message, ?string $hostKey = null): self
    {
        return new self(false, $message, $hostKey);
    }
}
