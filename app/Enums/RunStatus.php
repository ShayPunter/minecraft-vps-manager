<?php

namespace App\Enums;

/**
 * Where one VPS lifetime is. provisioning → booting → starting → online while active;
 * stopping → stopped, or failed, once it ends.
 */
enum RunStatus: string
{
    /** Asking the provider for a VPS. */
    case Provisioning = 'provisioning';
    /** The VPS exists and is booting (cloud-init installs Java and the server). */
    case Booting = 'booting';
    /** The VPS is up; waiting for Minecraft to accept connections. */
    case Starting = 'starting';
    case Online = 'online';
    /** Deleting the VPS. */
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Provisioning, self::Booting, self::Starting, self::Online, self::Stopping];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Creating the VPS',
            self::Booting => 'Booting',
            self::Starting => 'Starting Minecraft',
            self::Online => 'Online',
            self::Stopping => 'Stopping',
            self::Stopped => 'Stopped',
            self::Failed => 'Failed',
        };
    }
}
