<?php

namespace App\Servers;

use App\Models\Server;
use App\Models\WorldArchive;
use App\WorldStorage\S3Upload;
use App\WorldStorage\StorageSettings;
use InvalidArgumentException;

/**
 * The scripts that move a world between the VPS and world storage (#5288), run as root over
 * SSH, so worlds go straight between the two and never through the app's own server.
 *
 *   prepare: wait for cloud-init, download the current archive, check its size and SHA-256,
 *            unpack it into the server folder, start Minecraft.
 *   save:    (Minecraft already stopped) zip the server folder, upload it as `<path>.part`,
 *            download it again and compare the SHA-256, then rename it to `<path>`.
 *
 * The storage password or key only reaches the VPS while Minecraft isn't running: before the
 * first start and after the stop. It goes over SFTP into /root/.mvm (0600, the minecraft user
 * can't read /root) and the script deletes it when it ends. It's never in a command line,
 * so it never shows up in `ps`. SFTP pins the host key saved in Admin → World storage
 * (StrictHostKeyChecking); FTPS checks the server's certificate.
 *
 * Every value that ends up in a script was pattern-checked when it was saved, and is checked
 * again here; each is single-quoted on top of that.
 */
class WorldSync
{
    public const DIR = '/root/.mvm';

    public const PREPARE_COMMAND = 'sh '.self::DIR.'/prepare.sh';

    public const SAVE_COMMAND = 'sh '.self::DIR.'/save.sh';

    /** prepare.sh/save.sh exit with this when another one still holds the lock: try again later. */
    public const EXIT_BUSY = 75;

    // An archive path: `<slug>/<file>.zip`, as Archives::newPath() makes them.
    public const ARCHIVE_PATH_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\/[A-Za-z0-9._-]+\.zip$/';

    /**
     * The files PrepareServerRun puts on the VPS: the script, and the storage login when a
     * world has to be downloaded.
     *
     * @return array<string, array{0: string, 1: int}> remote path => [contents, mode]
     */
    public static function prepareFiles(Server $server, ?WorldArchive $archive, ?StorageSettings $storage, ?string $downloadUrl = null): array
    {
        self::checkSlug($server);

        $files = [self::DIR.'/prepare.sh' => [self::prepareScript($server, $archive, $storage), 0700]];

        if ($archive !== null) {
            if ($storage === null) {
                throw new InvalidArgumentException('Downloading a world needs the storage settings.');
            }

            if ($storage->isS3()) {
                // S3 (#5347): only a pre-signed link for this one archive, no keys.
                if ($downloadUrl === null) {
                    throw new InvalidArgumentException('Downloading a world from S3 needs a pre-signed link.');
                }

                return $files + [self::DIR.'/get.conf' => [self::curlUrl($downloadUrl), 0600]];
            }

            $files += self::loginFiles($storage);
        }

        return $files;
    }

    /**
     * The files StopServerRun puts on the VPS before SAVE_COMMAND.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function saveFiles(Server $server, StorageSettings $storage, ?S3Upload $upload = null): array
    {
        self::checkSlug($server);

        if ($storage->isS3()) {
            if ($upload === null || $upload->partUrls === []) {
                throw new InvalidArgumentException('Saving a world to S3 needs a started upload.');
            }

            self::checkStorage($storage);

            return [
                self::DIR.'/save.sh' => [self::s3SaveScript($server, $upload), 0700],
                // One curl config line per part, so the script copies line n into a file.
                self::DIR.'/parts' => [implode('', array_map(self::curlUrl(...), $upload->partUrls)), 0600],
                self::DIR.'/complete.conf' => [self::curlUrl($upload->completeUrl), 0600],
                self::DIR.'/get.conf' => [self::curlUrl($upload->readBackUrl), 0600],
            ];
        }

        return [self::DIR.'/save.sh' => [self::saveScript($server, $storage), 0700]] + self::loginFiles($storage);
    }

    /**
     * What StopServerRun runs: the save script with the archive path it should write.
     */
    public static function saveCommand(Server $server, string $archivePath): string
    {
        self::checkSlug($server);

        if (preg_match(self::ARCHIVE_PATH_PATTERN, $archivePath) !== 1 || ! str_starts_with($archivePath, $server->slug.'/')) {
            throw new InvalidArgumentException("Refusing archive path [{$archivePath}].");
        }

        return self::SAVE_COMMAND.' '.self::quote($archivePath);
    }

    /**
     * Read what save.sh printed: the archive's size and SHA-256, or that the folder was empty.
     *
     * @return array{size: int, sha256: string}|null null when there was nothing to save
     *
     * @throws InvalidArgumentException when the output has neither
     */
    public static function parseSaveOutput(string $output): ?array
    {
        if (preg_match('/^size=(\d+) sha256=([0-9a-f]{64})$/m', $output, $m) === 1) {
            return ['size' => (int) $m[1], 'sha256' => $m[2]];
        }

        if (preg_match('/^empty=1$/m', $output) === 1) {
            return null;
        }

        throw new InvalidArgumentException('The save script ended without saying what it saved.');
    }

    private static function prepareScript(Server $server, ?WorldArchive $archive, ?StorageSettings $storage): string
    {
        $fetch = '';

        if ($archive !== null && $storage !== null) {
            if (preg_match(self::ARCHIVE_PATH_PATTERN, $archive->path) !== 1) {
                throw new InvalidArgumentException("Refusing archive path [{$archive->path}].");
            }

            if (preg_match('/^[0-9a-f]{64}$/', $archive->sha256) !== 1) {
                throw new InvalidArgumentException('The archive has no valid SHA-256.');
            }

            $size = (string) $archive->size;
            $sha = $archive->sha256;
            $fetch = self::transferFunctions($storage)
                .'P='.self::quote($archive->path)."\n"
                .<<<'SH'
                fetch "$P" "$M/work/world.zip"
                size=$(stat -c %s "$M/work/world.zip")

                SH
                ."if [ \"\$size\" != '{$size}' ]; then echo \"The downloaded world is \$size bytes, but {$size} were saved.\"; exit 21; fi\n"
                .'sum=$(sha256sum "$M/work/world.zip" | cut -d " " -f 1)'."\n"
                ."if [ \"\$sum\" != '{$sha}' ]; then echo \"The downloaded world doesn't match the saved one (SHA-256 differs).\"; exit 22; fi\n"
                .<<<'SH'
                mkdir "$M/work/unpack"
                unzip -q "$M/work/world.zip" -d "$M/work/unpack"
                src="$M/work/unpack"
                # A zip holding one folder (most modpack server packs): use what's inside it.
                if [ "$(ls -A "$src" | wc -l)" = 1 ]; then
                    only=$(ls -A "$src")
                    if [ -d "$src/$only" ] && [ ! -L "$src/$only" ]; then src="$src/$only"; fi
                fi
                cp -a "$src/." "$DIR/"
                rm -rf "$M/work/unpack" "$M/work/world.zip"

                SH;
        }

        return self::header($server, 'put the world in place and start Minecraft')
            .<<<'SH'
            if systemctl is-active --quiet "$UNIT"; then echo "prepared (already running)"; exit 0; fi
            # The first boot installs Java and the unit; wait for it to finish.
            cloud-init status --wait >/dev/null 2>&1 || true
            for tool in java unzip; do
                if ! command -v "$tool" >/dev/null 2>&1; then
                    echo "$tool isn't installed: the VPS's first-boot setup (cloud-init) didn't finish."
                    exit 20
                fi
            done
            rm -rf "$M/work"
            mkdir -p "$M/work" "$DIR"

            SH
            .$fetch
            .<<<'SH'
            chown -R minecraft:minecraft /opt/minecraft
            systemctl start "$UNIT"
            echo prepared

            SH;
    }

    private static function saveScript(Server $server, StorageSettings $storage): string
    {
        return self::header($server, 'zip the server folder and upload it to world storage')
            .self::transferFunctions($storage)
            .<<<'SH'
            P="$1"
            case "$P" in
                *[!A-Za-z0-9._/-]*|*..*|/*) echo "Refusing archive path $P"; exit 2 ;;
            esac
            if systemctl is-active --quiet "$UNIT"; then echo "Minecraft is still running: not saving a live world."; exit 30; fi
            if [ ! -d "$DIR" ] || [ -z "$(ls -A "$DIR")" ]; then echo "empty=1"; exit 0; fi
            rm -rf "$M/work"
            mkdir -p "$M/work"
            (cd "$DIR" && zip -q -r -y "$M/work/save.zip" .)
            size=$(stat -c %s "$M/work/save.zip")
            sum=$(sha256sum "$M/work/save.zip" | cut -d " " -f 1)
            send "$M/work/save.zip" "$P.part"
            fetch "$P.part" "$M/work/check.zip"
            check=$(sha256sum "$M/work/check.zip" | cut -d " " -f 1)
            rm -f "$M/work/check.zip"
            if [ "$check" != "$sum" ]; then
                remove "$P.part" || true
                echo "The uploaded world didn't read back the same (SHA-256 differs): nothing was kept."
                exit 31
            fi
            finish "$P.part" "$P"
            rm -f "$M/work/save.zip"
            echo "size=$size sha256=$sum"

            SH;
    }

    private static function header(Server $server, string $what): string
    {
        $dir = CloudInit::BASE_DIR.'/'.$server->slug;
        $unit = CloudInit::unitName($server);

        return "#!/bin/sh\n# Minecraft VPS Manager (#5288): {$what}.\nset -eu\numask 077\n"
            .'M='.self::quote(self::DIR)."\n"
            .'DIR='.self::quote($dir)."\n"
            .'UNIT='.self::quote($unit)."\n"
            .<<<'SH'
            exec 9>"$M/lock"
            if ! flock -n 9; then echo "Another world setup or save is still running on this VPS."; exit 75; fi
            # The storage login is only here while this script runs.
            cleanup() { rm -rf "$M/work" "$M/key" "$M/password" "$M/askpass" "$M/curl.conf" "$M/known_hosts" "$M/get.conf" "$M/parts" "$M/complete.conf"; }
            trap cleanup EXIT

            SH;
    }

    /**
     * fetch <remote> <local>, send <local> <remote>, finish <remote.part> <remote> (rename),
     * remove <remote>. Remote paths are relative to the storage base folder.
     */
    private static function transferFunctions(StorageSettings $storage): string
    {
        self::checkStorage($storage);

        if ($storage->isS3()) {
            // The link in get.conf is for one object, so the remote path isn't needed.
            return self::s3Curl()
                .<<<'SH'
                fetch() { cu --config "$M/get.conf" -o "$2"; }

                SH;
        }

        $root = rtrim($storage->root, '/');

        if ($storage->driver === 'ftp') {
            $rootUrl = str_starts_with($root, '/') ? '%2F'.substr($root, 1) : $root;
            $base = 'ftp://'.$storage->host.':'.$storage->port.'/'.$rootUrl.'/';
            $tls = $storage->ftps ? ' --ssl-reqd' : '';

            return 'BASE='.self::quote($base)."\n"
                ."cu() { curl --config \"\$M/curl.conf\" --fail --silent --show-error --connect-timeout 20{$tls} \"\$@\"; }\n"
                .<<<'SH'
                fetch() { cu -o "$2" "$BASE$1"; }
                send() { cu --ftp-create-dirs -T "$1" "$BASE$2"; }
                finish() { cu -o /dev/null -Q "-RNFR $(basename "$1")" -Q "-RNTO $(basename "$2")" "$BASE$(dirname "$2")/"; }
                remove() { cu -o /dev/null -Q "-DELE $(basename "$1")" "$BASE$(dirname "$1")/"; }

                SH;
        }

        $auth = $storage->privateKey !== null
            ? '-i "$M/key" -o IdentitiesOnly=yes -o PreferredAuthentications=publickey'
            : '-o PubkeyAuthentication=no -o PreferredAuthentications=password,keyboard-interactive -o NumberOfPasswordPrompts=1';
        $env = $storage->privateKey !== null ? '' : 'SSH_ASKPASS="$M/askpass" SSH_ASKPASS_REQUIRE=force DISPLAY=none ';

        // -o BatchMode=no must come before -b: sftp's -b adds BatchMode=yes, and ssh keeps the
        // first value it sees. With BatchMode=yes the password (via askpass) is never asked for.
        return 'R='.self::quote($root)."\n"
            .'TARGET='.self::quote($storage->username.'@'.$storage->host)."\n"
            ."st() { {$env}sftp -q -o BatchMode=no -o StrictHostKeyChecking=yes -o UserKnownHostsFile=\"\$M/known_hosts\" "
            .'-o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=20 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 '
            ."-P {$storage->port} {$auth} -b \"\$M/work/batch\" \"\$TARGET\" </dev/null; }\n"
            .<<<'SH'
            fetch() { printf 'get "%s" "%s"\n' "$R/$1" "$2" > "$M/work/batch"; st; }
            send() {
                d=$(dirname "$2")
                : > "$M/work/batch"
                path="$R"
                printf '%s "%s"\n' -mkdir "$path" >> "$M/work/batch"
                for part in $(echo "$d" | tr '/' ' '); do
                    path="$path/$part"
                    printf '%s "%s"\n' -mkdir "$path" >> "$M/work/batch"
                done
                printf 'put "%s" "%s"\n' "$1" "$R/$2" >> "$M/work/batch"
                st
            }
            finish() { printf 'rename "%s" "%s"\n' "$R/$1" "$R/$2" > "$M/work/batch"; st; }
            remove() { printf 'rm "%s"\n' "$R/$1" > "$M/work/batch"; st; }

            SH;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    private static function loginFiles(StorageSettings $storage): array
    {
        self::checkStorage($storage);

        if ($storage->driver === 'ftp') {
            $password = (string) $storage->password;

            // curl's config file: a double-quoted value takes \\ \" \t \n \r escapes.
            $escaped = strtr($storage->username.':'.$password, ['\\' => '\\\\', '"' => '\\"', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r']);

            return [self::DIR.'/curl.conf' => ['user = "'.$escaped."\"\n", 0600]];
        }

        $blob = StorageSettings::hostKeyBlob($storage->hostKey);

        if ($blob === null) {
            throw new InvalidArgumentException('SFTP world storage has no pinned host key.');
        }

        [$type] = explode(' ', trim((string) $storage->hostKey));

        if (preg_match('/^[a-z0-9@.-]+$/', $type) !== 1) {
            throw new InvalidArgumentException('The pinned host key has an unknown type.');
        }

        $host = $storage->port === 22 ? $storage->host : "[{$storage->host}]:{$storage->port}";
        $files = [self::DIR.'/known_hosts' => ["{$host} {$type} ".base64_encode($blob)."\n", 0600]];

        if ($storage->privateKey !== null) {
            // OpenSSH refuses a key file without the final newline.
            $files[self::DIR.'/key'] = [rtrim(str_replace("\r\n", "\n", $storage->privateKey))."\n", 0600];
        } else {
            $files[self::DIR.'/password'] = [(string) $storage->password, 0600];
            $files[self::DIR.'/askpass'] = ["#!/bin/sh\ncat ".self::DIR."/password\n", 0700];
        }

        return $files;
    }

    private static function checkSlug(Server $server): void
    {
        if (preg_match(Server::SLUG_PATTERN, $server->slug) !== 1) {
            throw new InvalidArgumentException("Refusing a world script for slug [{$server->slug}].");
        }
    }

    /**
     * save.sh for S3 (#5347). The app has already started a multipart upload at the archive
     * path; this zips the folder, PUTs it in parts with the pre-signed links in $M/parts
     * (one part at a time, so the disk never holds a second copy), completes the upload with
     * the ETags S3 handed back, then downloads the finished object and compares the SHA-256.
     * Nothing is renamed: an unfinished multipart upload is invisible, and the app deletes
     * the object if the check (or its own size check) fails.
     */
    private static function s3SaveScript(Server $server, S3Upload $upload): string
    {
        if ($upload->partBytes % (1024 * 1024) !== 0) {
            throw new InvalidArgumentException('S3 part sizes are whole MB.');
        }

        $partMb = intdiv($upload->partBytes, 1024 * 1024);
        $maxParts = count($upload->partUrls);

        return self::header($server, 'zip the server folder and upload it to S3 world storage')
            .self::s3Curl()
            .<<<'SH'
            fetch() { cu --config "$M/get.conf" -o "$2"; }
            P="$1"
            case "$P" in
                *[!A-Za-z0-9._/-]*|*..*|/*) echo "Refusing archive path $P"; exit 2 ;;
            esac
            if systemctl is-active --quiet "$UNIT"; then echo "Minecraft is still running: not saving a live world."; exit 30; fi
            if [ ! -d "$DIR" ] || [ -z "$(ls -A "$DIR")" ]; then echo "empty=1"; exit 0; fi
            rm -rf "$M/work"
            mkdir -p "$M/work"
            (cd "$DIR" && zip -q -r -y "$M/work/save.zip" .)
            size=$(stat -c %s "$M/work/save.zip")
            sum=$(sha256sum "$M/work/save.zip" | cut -d " " -f 1)

            SH
            ."PART_MB={$partMb}\nMAX_PARTS={$maxParts}\n"
            .<<<'SH'
            PART=$((PART_MB * 1048576))
            parts=$(( (size + PART - 1) / PART ))
            if [ "$parts" -gt "$MAX_PARTS" ]; then
                echo "The world zip is $size bytes, more than one save can upload ($MAX_PARTS parts of $PART_MB MB). Raise MVM_S3_MAX_WORLD_GB."
                exit 32
            fi
            printf '<CompleteMultipartUpload>' > "$M/work/complete.xml"
            i=1
            while [ "$i" -le "$parts" ]; do
                dd if="$M/work/save.zip" of="$M/work/part" bs=1048576 skip=$(( (i - 1) * PART_MB )) count="$PART_MB" status=none
                sed -n "${i}p" "$M/parts" > "$M/work/part.conf"
                cu --config "$M/work/part.conf" -T "$M/work/part" -D "$M/work/headers" -o /dev/null
                etag=$(grep -i '^etag:' "$M/work/headers" | tail -n 1 | cut -d ':' -f 2- | tr -d ' \r')
                if [ -z "$etag" ]; then echo "World storage sent no ETag for part $i of $parts."; exit 33; fi
                printf '<Part><PartNumber>%s</PartNumber><ETag>%s</ETag></Part>' "$i" "$etag" >> "$M/work/complete.xml"
                rm -f "$M/work/part" "$M/work/part.conf" "$M/work/headers"
                i=$((i + 1))
            done
            printf '</CompleteMultipartUpload>' >> "$M/work/complete.xml"
            cu --config "$M/complete.conf" -X POST -H 'Content-Type: application/xml' --data-binary "@$M/work/complete.xml" -o "$M/work/complete.out"
            # S3 can answer 200 with an error in the body when completing.
            if ! grep -q 'CompleteMultipartUploadResult' "$M/work/complete.out"; then
                echo "World storage didn't finish the upload: $(head -c 300 "$M/work/complete.out")"
                exit 34
            fi
            fetch "$P" "$M/work/check.zip"
            check=$(sha256sum "$M/work/check.zip" | cut -d " " -f 1)
            rm -f "$M/work/check.zip"
            if [ "$check" != "$sum" ]; then
                echo "The uploaded world didn't read back the same (SHA-256 differs): nothing was kept."
                exit 31
            fi
            rm -f "$M/work/save.zip"
            echo "size=$size sha256=$sum"

            SH;
    }

    /**
     * curl for pre-signed links: the link itself is only ever in a --config file.
     */
    private static function s3Curl(): string
    {
        return <<<'SH'
            cu() { curl --fail --silent --show-error --connect-timeout 20 --retry 3 --retry-delay 2 "$@"; }

            SH;
    }

    /**
     * One curl config line: `url = "…"`, with the escapes a double-quoted value takes.
     */
    private static function curlUrl(string $url): string
    {
        if (preg_match('/^https?:\/\/[^\s"\\\\]+$/', $url) !== 1) {
            throw new InvalidArgumentException('Refusing a pre-signed link with spaces, quotes or backslashes.');
        }

        return 'url = "'.$url."\"\n";
    }

    private static function checkStorage(StorageSettings $storage): void
    {
        if ($storage->isS3()) {
            if (preg_match(StorageSettings::BUCKET_PATTERN, (string) $storage->bucket) !== 1
                || ($storage->root !== '' && (preg_match(StorageSettings::ROOT_PATTERN, $storage->root) !== 1 || str_contains($storage->root, '..')))) {
                throw new InvalidArgumentException('The world storage settings hold characters a script on the VPS must not see.');
            }

            return;
        }

        if (preg_match(StorageSettings::HOST_PATTERN, $storage->host) !== 1
            || preg_match(StorageSettings::USERNAME_PATTERN, $storage->username) !== 1
            || preg_match(StorageSettings::ROOT_PATTERN, $storage->root) !== 1
            || str_contains($storage->root, '..')
            || $storage->port < 1 || $storage->port > 65535) {
            throw new InvalidArgumentException('The world storage settings hold characters a script on the VPS must not see.');
        }
    }

    /**
     * Single quotes for sh by hand: escapeshellarg() quotes for the OS PHP runs on.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
