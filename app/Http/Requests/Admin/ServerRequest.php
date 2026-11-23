<?php

namespace App\Http\Requests\Admin;

use App\Cloud\CloudException;
use App\Dns\Dns;
use App\Enums\Visibility;
use App\Minecraft\Installer;
use App\Minecraft\InstallPlan;
use App\Minecraft\Modrinth\ModrinthException;
use App\Minecraft\Modrinth\ModrinthSource;
use App\Minecraft\Modrinth\ModrinthVersion;
use App\Minecraft\Packs\PackPlan;
use App\Minecraft\Software;
use App\Minecraft\VersionSource;
use App\Minecraft\VersionSourceException;
use App\Models\Server;
use App\Servers\ServerCatalog;
use App\Servers\ServerConfigForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create and edit a server. Admin-only (the route group), so authorize() just checks that.
 */
class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => is_string($this->input('slug')) ? strtolower(trim($this->input('slug'))) : $this->input('slug'),
            'visibility' => filled($this->input('visibility')) ? $this->input('visibility') : Visibility::Private->value,
            'eula' => $this->boolean('eula'),
            // Left out, it's a custom server, as every server was before #5351.
            'software' => filled($this->input('software')) ? $this->input('software') : Software::Custom->value,
            'icon_url' => filled($this->input('icon_url')) ? $this->input('icon_url') : null,
            'description' => filled($this->input('description')) ? $this->input('description') : null,
            'subdomain' => $this->normalisedSubdomain(),
            'wake_on_join' => $this->boolean('wake_on_join'),
        ]);
    }

    /**
     * Lowercase, no trailing dot, and a full name inside the zone ("atm9.example.com") cut
     * down to the part before the domain.
     */
    private function normalisedSubdomain(): ?string
    {
        $value = $this->input('subdomain');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = rtrim(strtolower(trim($value)), '.');
        $domain = app(Dns::class)->saved()?->domain;

        if ($domain !== null && str_ends_with($value, '.'.$domain)) {
            $value = substr($value, 0, -strlen('.'.$domain));
        }

        return $value;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $server = $this->route('server');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'required', 'string', 'max:40', 'regex:'.Server::SLUG_PATTERN,
                Rule::unique(Server::class, 'slug')->ignore($server instanceof Server ? $server->id : null),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['required', Rule::enum(Visibility::class)],
            // The Minecraft EULA (#5367): ticked, starts write eula.txt with eula=true.
            'eula' => ['boolean'],
            'icon_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'server_type' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'location' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            // A shell command line that runs on the server's own VPS (an admin's choice).
            // One line only: a newline would start a second command in the start script.
            // What it runs (#5351). For Vanilla/Paper/Fabric the app sets the start command
            // and Java itself (resolveInstall()), so the form's fields for those are ignored.
            'software' => ['required', Rule::enum(Software::class)],
            'minecraft_version' => ['exclude_unless:software,vanilla,paper,fabric', 'required', 'string', 'max:40', 'regex:'.InstallPlan::VERSION_PATTERN],
            'start_command' => ['exclude_unless:software,custom', 'required', 'string', 'max:500', 'not_regex:/[\r\n\0]/'],
            // A server-pack URL doesn't say which Java it needs, so the admin picks it (#5352).
            'java_version' => ['exclude_unless:software,custom,pack_url', 'required', 'integer', Rule::in(config('minecraft.java_versions'))],
            'pack_url' => ['exclude_unless:software,pack_url', 'required', 'string', 'max:2048', 'url:https', 'regex:'.PackPlan::URL_PATTERN],
            'modrinth_project_id' => ['exclude_unless:software,modrinth', 'required', 'string', 'regex:'.ModrinthVersion::ID_PATTERN],
            'modrinth_version_id' => ['exclude_unless:software,modrinth', 'required', 'string', 'regex:'.ModrinthVersion::ID_PATTERN],
            'idle_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'subdomain' => [
                'nullable', 'string', 'max:190', 'regex:'.Server::SUBDOMAIN_PATTERN,
                Rule::unique(Server::class, 'subdomain')->ignore($server instanceof Server ? $server->id : null),
            ],
            // While stopped, its name points at the waker and a join starts it (#5620).
            'wake_on_join' => ['boolean'],
            // server.properties, whitelist/ops, run.sh / user_jvm_args.txt (#5377).
            ...ServerConfigForm::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, digits and single dashes only (e.g. all-the-mods-9).',
            'start_command.not_regex' => 'Keep the start command on one line.',
            'subdomain.regex' => 'Use lowercase letters, digits and dashes, with dots between parts (e.g. atm9 or mc.atm9).',
            'subdomain.unique' => 'Another server already uses that name.',
            'minecraft_version.required' => 'Pick a Minecraft version.',
            'pack_url.required' => 'Paste the server pack\'s download link.',
            'pack_url.url' => 'Use the https link to the server pack\'s zip.',
            'pack_url.regex' => 'Use the https link to the server pack\'s zip, without spaces or quotes.',
            'modrinth_project_id.required' => 'Search for a modpack and pick one.',
            'modrinth_project_id.regex' => 'Search for a modpack and pick one.',
            'modrinth_version_id.required' => 'Pick a version of the modpack.',
            'modrinth_version_id.regex' => 'Pick a version of the modpack.',
            ...ServerConfigForm::messages(),
        ];
    }

    /** @var array<string, mixed>|null the start command, Java and pack fields worked out for an install */
    private ?array $installSettings = null;

    /** @var array<string, mixed>|null what ServerConfigForm worked out (#5377) */
    private ?array $config = null;

    /**
     * The validated attributes to save: the start command and Java worked out for an
     * installed server, no Minecraft version for a custom one, and only the config changes
     * that differ from the saved world.
     *
     * @return array<string, mixed>
     */
    public function serverAttributes(): array
    {
        $attributes = $this->safe()->except(['eula', ...array_keys(ServerConfigForm::rules())]);
        $software = Software::tryFrom((string) ($attributes['software'] ?? ''));

        if ($software === Software::Custom || $software === Software::PackUrl) {
            $attributes['minecraft_version'] = null;
        }

        // Only the chosen software's own fields are kept (#5352).
        if ($software !== Software::PackUrl) {
            $attributes['pack_url'] = null;
        }

        if ($software !== Software::Modrinth) {
            $attributes = [...$attributes, 'modrinth_project_id' => null, 'modrinth_version_id' => null, 'modrinth_title' => null];
        }

        if ($software === null || ! $software->isPack()) {
            $attributes['heap_mb'] = null;
        }

        return [...$attributes, ...($this->installSettings ?? []), ...($this->config ?? [])];
    }

    /**
     * When the provider's list is available, the type must exist there and be sold in the location.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $subdomain = $this->input('subdomain');
                $domain = app(Dns::class)->saved()?->domain;

                if (is_string($subdomain) && $domain !== null && ! $validator->errors()->has('subdomain')) {
                    $name = "{$subdomain}.{$domain}";
                    $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

                    if ($name === $appHost) {
                        $validator->errors()->add('subdomain', "{$name} is this app's own address. Pick another name.");
                    } elseif (strlen($name) > 253) {
                        $validator->errors()->add('subdomain', 'That name is too long for DNS.');
                    }
                }

                // Players wake it by joining its name (#5620), so it needs one.
                if ($this->boolean('wake_on_join') && ! is_string($subdomain)) {
                    $validator->errors()->add('wake_on_join', 'Give it a subdomain first: players join that name to wake it.');
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['server_type', 'location'])) {
                    return;
                }

                $catalog = app(ServerCatalog::class);

                try {
                    $type = $catalog->findType((string) $this->input('server_type'));
                    $locations = array_map(fn ($location) => $location->name, $catalog->locations());
                } catch (CloudException) {
                    return; // Provider unreachable: the regex checks above are all we can do.
                }

                $location = (string) $this->input('location');

                if (! in_array($location, $locations, true)) {
                    $validator->errors()->add('location', 'Pick a location from the list.');
                } elseif ($type === null) {
                    $validator->errors()->add('server_type', 'Pick a server type from the list.');
                } elseif ($type->retiredIn($location)) {
                    $validator->errors()->add('server_type', "{$type->description} is no longer offered in {$location}. Pick another type.");
                } elseif (! $type->availableIn($location)) {
                    $validator->errors()->add('server_type', "{$type->description} isn't available in {$location}.");
                }
            },
            function (Validator $validator): void {
                $this->resolveInstall($validator);
            },
            function (Validator $validator): void {
                $server = $this->route('server');
                $this->config = ServerConfigForm::resolve($validator, $this->all(), $server instanceof Server ? $server : null);
            },
        ];
    }

    /**
     * Vanilla, Paper or Fabric (#5351): the version must be one its source lists, the Java it
     * needs one this app installs, and the memory flags follow the server type's RAM.
     */
    private function resolveInstall(Validator $validator): void
    {
        $software = Software::tryFrom((string) $this->input('software'));

        if ($software === null || ! $software->installs() || $validator->errors()->hasAny(['software', 'minecraft_version', 'server_type', 'location'])) {
            return;
        }

        if ($software->isPack()) {
            $this->resolvePack($validator, $software);

            return;
        }

        $version = (string) $this->input('minecraft_version');
        $source = app(VersionSource::class);

        try {
            if (! in_array($version, $source->versions($software), true)) {
                $validator->errors()->add('minecraft_version', "{$software->label()} has no release {$version}. Pick a version from the list.");

                return;
            }

            $required = $source->javaFor($software, $version);
        } catch (VersionSourceException $e) {
            $validator->errors()->add('minecraft_version', "{$e->getMessage()} Try again in a minute.");

            return;
        }

        $java = Installer::javaFor($required);

        if ($java === null) {
            $validator->errors()->add('minecraft_version', "Minecraft {$version} needs Java {$required}, which this app can't install yet.");

            return;
        }

        try {
            $type = app(ServerCatalog::class)->findType((string) $this->input('server_type'));
        } catch (CloudException $e) {
            $validator->errors()->add('server_type', "Couldn't read the server types to size Java's memory: {$e->getMessage()} Try again in a minute.");

            return;
        }

        if ($type === null) {
            $validator->errors()->add('server_type', 'Pick a server type from the list.');

            return;
        }

        $this->installSettings = [
            'java_version' => $java,
            'start_command' => Installer::startCommand($software, $type->memoryGb),
        ];
    }

    /**
     * A modpack (#5352): it starts with the mvm-launch.sh its install writes, and its memory
     * follows the server type's RAM. A Modrinth version must belong to the picked project and
     * name a Minecraft version this app knows, whose Java it then installs.
     */
    private function resolvePack(Validator $validator, Software $software): void
    {
        if ($validator->errors()->hasAny(['pack_url', 'java_version', 'modrinth_project_id', 'modrinth_version_id'])) {
            return;
        }

        try {
            $type = app(ServerCatalog::class)->findType((string) $this->input('server_type'));
        } catch (CloudException $e) {
            $validator->errors()->add('server_type', "Couldn't read the server types to size Java's memory: {$e->getMessage()} Try again in a minute.");

            return;
        }

        if ($type === null) {
            $validator->errors()->add('server_type', 'Pick a server type from the list.');

            return;
        }

        $settings = ['start_command' => Installer::PACK_START_COMMAND, 'heap_mb' => Installer::heapMb($type->memoryGb)];

        if ($software === Software::Modrinth) {
            try {
                $modrinth = app(ModrinthSource::class);
                $version = $modrinth->version((string) $this->input('modrinth_version_id'));

                if ($version->projectId !== $this->input('modrinth_project_id')) {
                    $validator->errors()->add('modrinth_version_id', 'That version belongs to another modpack. Pick the version again.');

                    return;
                }

                $title = $modrinth->title($version->projectId);
                $required = app(VersionSource::class)->javaFor(Software::Vanilla, $version->minecraftVersion);
            } catch (ModrinthException $e) {
                $validator->errors()->add('modrinth_version_id', $e->getMessage().($e->permanent ? '' : ' Try again in a minute.'));

                return;
            } catch (VersionSourceException) {
                $validator->errors()->add('modrinth_version_id', "This version is for Minecraft {$version->minecraftVersion}, which this app can't install (1.17 on).");

                return;
            }

            $java = Installer::javaFor($required);

            if ($java === null) {
                $validator->errors()->add('modrinth_version_id', "Minecraft {$version->minecraftVersion} needs Java {$required}, which this app can't install yet.");

                return;
            }

            $settings += [
                'java_version' => $java,
                'minecraft_version' => $version->minecraftVersion,
                'modrinth_title' => mb_substr($title, 0, 200),
            ];
        }

        $this->installSettings = $settings;
    }
}
