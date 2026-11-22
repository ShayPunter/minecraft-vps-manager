<?php

namespace App\WorldStorage;

use RuntimeException;

/**
 * World storage can't be used. The message is safe to show an admin (no secrets).
 */
class StorageUnavailable extends RuntimeException
{
    public static function notSetUp(): self
    {
        return new self('World storage isn\'t set up yet: fill it in under Admin → World storage.');
    }

    public static function secretsUnreadable(): self
    {
        return new self('The saved world storage password or key can\'t be read, because APP_KEY has changed since they were saved. Enter them again under Admin → World storage.');
    }

    public static function incomplete(StorageSettings $settings): self
    {
        if ($settings->driver === 'sftp' && $settings->hostKey === null) {
            return new self('The storage server\'s host key isn\'t pinned yet: save the settings under Admin → World storage (that tests the connection and pins it).');
        }

        return new self($settings->driver === 'sftp'
            ? 'World storage needs a password or a private key: add one under Admin → World storage.'
            : 'FTP world storage needs a password: add one under Admin → World storage.');
    }
}
