<?php

namespace App\WorldStorage;

use ZipArchive;

/**
 * Checks an uploaded world or modpack zip before it goes to storage: every entry has to
 * unpack inside the server folder (no absolute paths, no "..", no drive letters or
 * backslashes) and none may be a symlink, which could point anywhere on the VPS.
 */
class ZipInspector
{
    /**
     * @return int how many entries the zip holds
     *
     * @throws UnsafeArchive
     */
    public static function assertSafe(string $path): int
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new UnsafeArchive('That file isn\'t a zip archive.');
        }

        try {
            if ($zip->numFiles === 0) {
                throw new UnsafeArchive('The zip is empty.');
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (! is_string($name) || ! self::isSafeName($name)) {
                    throw new UnsafeArchive('The zip has an entry that would unpack outside the server folder ('
                        .(is_string($name) ? mb_substr(str_replace("\0", '\0', $name), 0, 120) : "entry {$i}").').');
                }

                $opsys = 0;
                $attributes = 0;

                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new UnsafeArchive("The zip has a symbolic link ({$name}), which isn't allowed.");
                }
            }

            return $zip->numFiles;
        } finally {
            $zip->close();
        }
    }

    public static function isSafeName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
            return false;
        }

        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
