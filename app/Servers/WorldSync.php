<?php

namespace App\Servers;

use App\Minecraft\InstallPlan;
use App\Minecraft\Packs\PackPlan;
use App\Minecraft\Software;
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
    public static function prepareFiles(Server $server, ?WorldArchive $archive, ?StorageSettings $storage, ?string $downloadUrl = null, InstallPlan|PackPlan|null $install = null): array
    {
        self::checkSlug($server);

        if ($archive !== null && $install !== null) {
            throw new InvalidArgumentException('A server with a saved world is never installed over (#5351).');
        }

        // The admin's server.properties / whitelist / ops / run.sh changes (#5377).
        $files = [self::DIR.'/prepare.sh' => [self::prepareScript($server, $archive, $storage, $install), 0700]]
            + ServerFiles::overrideFiles($server);

        if ($install instanceof PackPlan && $install->files !== []) {
            // A Modrinth pack's index (#5352): one `<sha512> <url> <path>` line per file.
            $files[self::DIR.'/mrfiles'] = [$install->filesList(), 0600];
        }

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
     * @return array{size: int, sha256: string, files: array<string, string>}|null null when there was nothing to save
     *
     * @throws InvalidArgumentException when the output has neither
     */
    public static function parseSaveOutput(string $output): ?array
    {
        if (preg_match('/^size=(\d+) sha256=([0-9a-f]{64})$/m', $output, $m) === 1) {
            return ['size' => (int) $m[1], 'sha256' => $m[2], 'files' => ServerFiles::fromSaveOutput($output)];
        }

        if (preg_match('/^empty=1$/m', $output) === 1) {
            return null;
        }

        throw new InvalidArgumentException('The save script ended without saying what it saved.');
    }

    /**
     * check_zip <zip> <what>: the same rules as a world upload (ZipInspector), for server
     * packs, .mrpacks and, since #5527, saved worlds: nothing outside the folder, no symlinks.
     */
    private static function checkZipFunction(): string
    {
        return <<<'SH'
            check_zip() {
                if ! unzip -tqq "$1" >/dev/null 2>&1; then echo "$2 isn't a readable zip file."; exit 27; fi
                bad=$(zipinfo -1 "$1" | grep -E '^/|^[A-Za-z]:|\\|(^|/)\.\.(/|$)' | head -n 1 || true)
                if [ -n "$bad" ]; then echo "$2 has an entry that would unpack outside the server folder: not unpacking it."; exit 27; fi
                if zipinfo "$1" | grep -q '^l'; then echo "$2 holds a symbolic link, which isn't allowed: not unpacking it."; exit 27; fi
            }

            SH;
    }

    /**
     * prepare.sh, once the world or install is in place (#5527): root writes eula.txt,
     * server.properties, user_jvm_args.txt and the rest next, so no symbolic link may be left
     * in the folder for it to write through (a mod or installer could make one). Each one
     * removed is named in the log.
     */
    public static function unlinkScript(): string
    {
        return <<<'SH'
            # No symbolic links in the server folder: root writes files there next (#5527).
            find "$DIR" -type l > "$M/work/links"
            while IFS= read -r link; do
                echo "Removed the symbolic link ${link#"$DIR"/}: links aren't allowed in the server folder."
                rm -f "$link"
            done < "$M/work/links"
            rm -f "$M/work/links"

            SH;
    }

    /**
     * save.sh's zip step: symbolic links are left out of the save (#5527), each named in the
     * output, since a restore refuses them. `-y` stays so zip never follows one.
     */
    private static function zipStep(): string
    {
        return <<<'SH'
            find "$DIR" -type l > "$M/work/links"
            while IFS= read -r link; do
                echo "Not saving the symbolic link ${link#"$DIR"/}."
                rm -f "$link"
            done < "$M/work/links"
            rm -f "$M/work/links"
            (cd "$DIR" && zip -q -r -y "$M/work/save.zip" .)

            SH;
    }

    /**
     * A fresh install (#5351): download each file, check it against the hash its source
     * published, and for Fabric run the installer in the server folder (it fetches the
     * loader's libraries from Fabric's Maven and writes fabric-server-launch.jar). The URLs,
     * names, hashes and versions were all checked by Download / InstallPlan.
     */
    private static function installScript(InstallPlan $install): string
    {
        $script = "# No saved world yet: install {$install->description} (#5351).\n"
            .'echo '.self::quote("Installing {$install->description}")."\n"
            .<<<'SH'
            for tool in curl sha1sum sha256sum; do
                if ! command -v "$tool" >/dev/null 2>&1; then echo "$tool isn't installed on the VPS."; exit 20; fi
            done
            get() {
                if ! curl -fsSL --proto '=https' --retry 3 --retry-delay 5 --connect-timeout 20 -o "$2" "$1"; then
                    echo "Couldn't download $1."
                    exit 23
                fi
                sum=$("${3}sum" "$2" | cut -d " " -f 1)
                if [ "$sum" != "$4" ]; then
                    echo "$(basename "$2") doesn't match the $3 its source published: not installing it."
                    rm -f "$2"
                    exit 24
                fi
            }

            SH;

        foreach ($install->downloads as $download) {
            $target = ($download->intoServerFolder ? '"$DIR"/' : '"$M/work"/').$download->file;
            $script .= 'get '.self::quote($download->url).' '.$target.' '.$download->algorithm.' '.self::quote($download->hash)."\n";
        }

        if ($install->software === Software::Fabric) {
            $installer = $install->installer();

            $script .= 'echo '.self::quote("Running the Fabric installer (loader {$install->fabricLoader})")."\n"
                .'if ! java -jar "$M/work/'.$installer?->file.'" server -mcversion '.self::quote($install->version)
                .' -loader '.self::quote((string) $install->fabricLoader).' -dir "$DIR"; then echo "The Fabric installer failed."; exit 25; fi'."\n"
                .'if [ ! -f "$DIR/fabric-server-launch.jar" ]; then echo "The Fabric installer wrote no fabric-server-launch.jar."; exit 25; fi'."\n";
        }

        return $script."\n";
    }

    /**
     * A modpack install (#5352), see PackPlan. Exits: 23 download failed, 24 hash differs,
     * 25 an installer failed, 26 no way to start the pack found, 27 the zip isn't safe.
     */
    private static function packScript(PackPlan $plan): string
    {
        $script = "# No saved world yet: install {$plan->description} (#5352).\n"
            .'echo '.self::quote("Installing {$plan->description}")."\n"
            .<<<'SH'
            for tool in curl sha256sum sha512sum zipinfo; do
                if ! command -v "$tool" >/dev/null 2>&1; then echo "$tool isn't installed on the VPS."; exit 20; fi
            done
            dl() { curl -fsSL --globoff --proto '=https' --proto-redir '=https' --retry 3 --retry-delay 5 --connect-timeout 20 -o "$2" "$1"; }
            get() {
                if ! dl "$1" "$2"; then echo "Couldn't download $1."; exit 23; fi
                sum=$("${3}sum" "$2" | cut -d " " -f 1)
                if [ "$sum" != "$4" ]; then
                    echo "$(basename "$2") doesn't match the $3 its source published: not installing it."
                    rm -f "$2"
                    exit 24
                fi
            }

            SH;

        if ($plan->software === Software::PackUrl) {
            $script .= 'U='.self::quote((string) $plan->packUrl)."\n"
                .<<<'SH'
                if ! dl "$U" "$M/work/pack.zip"; then echo "Couldn't download the server pack."; exit 23; fi
                check_zip "$M/work/pack.zip" "The server pack"
                # No hash is published for a server-pack URL, so the log keeps what was installed.
                echo "Server pack: $(stat -c %s "$M/work/pack.zip") bytes, SHA-256 $(sha256sum "$M/work/pack.zip" | cut -d " " -f 1)"
                mkdir "$M/work/unpack"
                unzip -q "$M/work/pack.zip" -d "$M/work/unpack"
                rm -f "$M/work/pack.zip"
                src="$M/work/unpack"
                # A zip holding one folder: use what's inside it.
                if [ "$(ls -A "$src" | wc -l)" = 1 ]; then
                    only=$(ls -A "$src")
                    if [ -d "$src/$only" ] && [ ! -L "$src/$only" ]; then src="$src/$only"; fi
                fi
                cp -a "$src/." "$DIR/"
                rm -rf "$M/work/unpack"
                # An installer-style pack (All the Mods 10 ships neoforge-…-installer.jar and a
                # script that installs, then restarts the server forever): run the installer only.
                if [ ! -d "$DIR/libraries" ]; then
                    inst=$(find "$DIR" -maxdepth 1 -type f \( -name 'neoforge-*-installer.jar' -o -name 'forge-*-installer.jar' \) | sort | tail -n 1)
                    if [ -n "$inst" ]; then
                        echo "Running the pack's $(basename "$inst")"
                        if ! (cd "$DIR" && java -jar "$inst" --installServer); then echo "The pack's installer failed."; exit 25; fi
                    fi
                fi

                SH;
        } else {
            $mrpack = $plan->mrpack;
            $loader = $plan->loader;

            if ($mrpack === null || $loader === null) {
                throw new InvalidArgumentException('A Modrinth install needs the .mrpack and its loader.');
            }

            $script .= 'get '.self::quote($mrpack->url).' "$M/work/pack.mrpack" sha512 '.self::quote($mrpack->hash)."\n"
                .<<<'SH'
                check_zip "$M/work/pack.mrpack" "The .mrpack"
                n=0
                if [ -f "$M/mrfiles" ]; then
                    while IFS=' ' read -r sha url path; do
                        [ -n "$path" ] || continue
                        case "$path" in /*|*..*) echo "Refusing $path from the pack's index."; exit 27 ;; esac
                        mkdir -p "$(dirname "$DIR/$path")"
                        if ! dl "$url" "$DIR/$path"; then echo "Couldn't download $path."; exit 23; fi
                        sum=$(sha512sum "$DIR/$path" | cut -d " " -f 1)
                        if [ "$sum" != "$sha" ]; then echo "$path doesn't match the SHA-512 in the pack's index: not installing it."; rm -f "$DIR/$path"; exit 24; fi
                        n=$((n + 1))
                    done < "$M/mrfiles"
                fi
                echo "Downloaded $n files from the pack's index."
                mkdir "$M/work/mr"
                unzip -q "$M/work/pack.mrpack" -d "$M/work/mr"
                # Modrinth's format: overrides/ first, then server-overrides/ on top.
                for o in overrides server-overrides; do
                    if [ -d "$M/work/mr/$o" ]; then cp -a "$M/work/mr/$o/." "$DIR/"; fi
                done
                rm -rf "$M/work/mr" "$M/work/pack.mrpack"

                SH;

            foreach ($loader->downloads as $download) {
                $target = ($download->intoServerFolder ? '"$DIR"/' : '"$M/work"/').$download->file;
                $script .= 'get '.self::quote($download->url).' '.$target.' '.$download->algorithm.' '.self::quote($download->hash)."\n";
            }

            $installer = '"$M/work/'.$loader->installer()?->file.'"';
            $mc = self::quote($loader->minecraftVersion);
            $version = self::quote($loader->loaderVersion);

            $script .= 'echo '.self::quote("Installing {$loader->label()}")."\n".match ($loader->loader) {
                'fabric' => "if ! java -jar {$installer} server -mcversion {$mc} -loader {$version} -dir \"\$DIR\"; then echo \"The Fabric installer failed.\"; exit 25; fi\n",
                'quilt' => "if ! java -jar {$installer} install server {$mc} {$version} --install-dir=\"\$DIR\"; then echo \"The Quilt installer failed.\"; exit 25; fi\n",
                default => "if ! (cd \"\$DIR\" && java -jar {$installer} --installServer); then echo \"The {$loader->label()} installer failed.\"; exit 25; fi\n",
            };
        }

        return $script.self::launchScript();
    }

    /**
     * Writes mvm-launch.sh (#5352): Java straight away, never a pack's own restart loop. A
     * Forge/NeoForge install's unix_args.txt first, then a Fabric/Quilt launcher, then a
     * plain server.jar. Public for the test that runs it with a real sh.
     */
    public static function launchScript(): string
    {
        return <<<'SH'
            args=$(cd "$DIR" && find libraries/net/neoforged/neoforge libraries/net/minecraftforge/forge -name unix_args.txt 2>/dev/null | sort -V | tail -n 1)
            if [ -n "$args" ]; then
                case "$args" in *[!A-Za-z0-9._/+-]*) echo "Refusing the loader's argument file $args."; exit 26 ;; esac
                launch="java @user_jvm_args.txt @$args nogui"
            elif [ -f "$DIR/fabric-server-launch.jar" ]; then
                launch="java @user_jvm_args.txt -jar fabric-server-launch.jar nogui"
            elif [ -f "$DIR/quilt-server-launch.jar" ]; then
                launch="java @user_jvm_args.txt -jar quilt-server-launch.jar nogui"
            elif [ -f "$DIR/server.jar" ]; then
                launch="java @user_jvm_args.txt -jar server.jar nogui"
            else
                echo "Couldn't find how to start this pack: no unix_args.txt from a Forge or NeoForge install, and no fabric-server-launch.jar, quilt-server-launch.jar or server.jar."
                exit 26
            fi
            printf '#!/bin/sh\n# Written by Minecraft VPS Manager (#5352) when it installed this pack.\nexec %s "$@"\n' "$launch" > "$DIR/mvm-launch.sh"
            echo "Starts with: $launch"

            SH;
    }

    /**
     * A modpack's memory (#5352), on every start: -Xms/-Xmx from the server type's RAM go
     * into user_jvm_args.txt (the pack's own lines replaced, the rest kept), with a warning
     * in the log when the pack asks for more than the type leaves Java. An admin's own
     * user_jvm_args.txt (#5377) is copied over it afterwards and wins.
     */
    public static function heapScript(int $heapMb): string
    {
        return "HEAP={$heapMb}\n".<<<'SH'
            J="$DIR/user_jvm_args.txt"
            if [ -f "$J" ]; then
                asked=$(grep -E '^-Xmx[0-9]+[GgMm]$' "$J" | tail -n 1 | sed 's/^-Xmx//' || true)
                if [ -n "$asked" ]; then
                    num=${asked%?}
                    case "$asked" in *[Gg]) mb=$((num * 1024)) ;; *) mb=$num ;; esac
                    if [ "$mb" -gt "$HEAP" ]; then
                        echo "The pack asks for -Xmx$asked, but this server type leaves Java ${HEAP} MB: it may run out of memory. Pick a server type with more RAM."
                    fi
                fi
                grep -v -E '^-Xm[sx][0-9]' "$J" > "$M/work/jvm" || true
                rm -f "$J"
                cat "$M/work/jvm" > "$J"
                rm -f "$M/work/jvm"
            fi
            printf -- '-Xms%sM\n-Xmx%sM\n' "$HEAP" "$HEAP" >> "$J"
            echo "Java memory: ${HEAP} MB (from the server type)."

            SH;
    }

    private static function prepareScript(Server $server, ?WorldArchive $archive, ?StorageSettings $storage, InstallPlan|PackPlan|null $install = null): string
    {
        $fetch = match (true) {
            $install instanceof InstallPlan => self::installScript($install),
            $install instanceof PackPlan => self::packScript($install),
            default => '',
        };

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
                # A saved world gets the upload rules too (#5527): saves from before then kept links.
                check_zip "$M/work/world.zip" "The saved world"
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
            # The log shipper (#5367) goes first, so the setup output and everything after it
            # reach the app's log even if the rest fails.
            if [ -f /root/.mvm-log/mvm-log-ship.service ]; then
                install -m 0644 /root/.mvm-log/mvm-log-ship.service /etc/systemd/system/mvm-log-ship.service
                systemctl daemon-reload
                systemctl restart mvm-log-ship.service || echo "The log shipper didn't start."
            fi
            for tool in java unzip zipinfo; do
                if ! command -v "$tool" >/dev/null 2>&1; then
                    echo "$tool isn't installed: the VPS's first-boot setup (cloud-init) didn't finish."
                    exit 20
                fi
            done
            rm -rf "$M/work"
            mkdir -p "$M/work" "$DIR"

            SH
            .self::checkZipFunction()
            .$fetch
            .self::unlinkScript()
            .($server->software->isPack() && $server->heap_mb !== null ? self::heapScript($server->heap_mb) : '')
            .ServerFiles::applyScript()
            .<<<'SH'
            # A start command like ./run.sh: zips made on Windows lose the executable bit.
            find "$DIR" -maxdepth 1 -type f -name '*.sh' -exec chmod 0755 {} +

            SH
            .($server->eula_accepted_at !== null
                // An admin accepted the Minecraft EULA for this server (#5367): Minecraft won't start without it.
                ? "rm -f \"\$DIR/eula.txt\"\nprintf '%s\\n' '#Accepted for this server in Minecraft VPS Manager (https://aka.ms/MinecraftEULA)' 'eula=true' > \"\$DIR/eula.txt\"\n"
                : '')
            .<<<'SH'
            # Zips can store entries with no permissions at all (a real .mrpack's overrides came out
            # as ---------- on Nov 23, #5352): the owner must be able to read and write everything.
            chmod -R u+rwX "$DIR"
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

            SH
            .self::zipStep()
            .<<<'SH'
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
            # The files admins edit in the app, as saved (#5377, ServerFiles).
            for name in server.properties run.sh user_jvm_args.txt whitelist.json ops.json; do
                if [ -f "$DIR/$name" ] && [ ! -L "$DIR/$name" ] && [ "$(stat -c %s "$DIR/$name")" -le 65536 ]; then
                    echo "mvmfile $name $(base64 -w 0 "$DIR/$name")"
                fi
            done
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
            cleanup() { rm -rf "$M/work" "$M/key" "$M/password" "$M/askpass" "$M/curl.conf" "$M/known_hosts" "$M/get.conf" "$M/parts" "$M/complete.conf" "$M/files" "$M/mrfiles"; }
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

            SH
            .self::zipStep()
            .<<<'SH'
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
            # The files admins edit in the app, as saved (#5377, ServerFiles).
            for name in server.properties run.sh user_jvm_args.txt whitelist.json ops.json; do
                if [ -f "$DIR/$name" ] && [ ! -L "$DIR/$name" ] && [ "$(stat -c %s "$DIR/$name")" -le 65536 ]; then
                    echo "mvmfile $name $(base64 -w 0 "$DIR/$name")"
                fi
            done
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
