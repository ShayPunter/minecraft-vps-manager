<?php

namespace App\Enums;

/**
 * Where one VPS lifetime is. provisioning → booting → starting → online while active;
 * stopping → stopped, or failed, once it ends. stop_failed is still active: the VPS exists.
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
    /** Saving the world (graceful stop over SSH), then deleting the VPS. */
    case Stopping = 'stopping';
    /** The save or the delete failed: the VPS is kept (still costs money) and an admin is alerted. */
    case StopFailed = 'stop_failed';
    case Stopped = 'stopped';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Provisioning, self::Booting, self::Starting, self::Online, self::Stopping, self::StopFailed];
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
            self::StopFailed => 'Stop failed (VPS kept)',
            self::Stopped => 'Stopped',
            self::Failed => 'Failed',
        };
    }
}
