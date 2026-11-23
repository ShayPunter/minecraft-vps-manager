<?php

namespace App\Minecraft\Modrinth;

use RuntimeException;

/**
 * Modrinth couldn't be asked, or a pack can't be installed (#5352). The message is safe to
 * show to admins. $permanent means trying again won't help (a broken or unsafe pack).
 */
class ModrinthException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
