<?php

namespace App\Dns;

use RuntimeException;

/**
 * The DNS provider refused or couldn't be reached. The message is safe to show (never the token).
 */
class DnsException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
