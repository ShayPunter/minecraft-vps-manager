<?php

namespace App\Servers;

final readonly class ShellResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
