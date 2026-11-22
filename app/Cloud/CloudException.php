<?php

namespace App\Cloud;

use RuntimeException;

/**
 * Any failure talking to the cloud provider. `errorCode` is Hetzner's machine-readable
 * code (`invalid_input`, `rate_limit_exceeded`, …) or one of the app's own:
 * `not_configured`, `not_managed`, `connection_failed`, `unexpected_response`.
 */
class CloudException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
