<?php

namespace App\Http\Requests\Admin;

use App\Cloud\CloudException;
use App\Dns\Dns;
use App\Minecraft\Installer;
use App\Minecraft\InstallPlan;
use App\Minecraft\Software;
use App\Minecraft\VersionSource;
use App\Minecraft\VersionSourceException;
use App\Models\Server;
use App\Servers\ServerCatalog;
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
            'is_public' => $this->boolean('is_public'),
            'eula' => $this->boolean('eula'),
            // Left out, it's a custom server, as every server was before #5351.
            'software' => filled($this->input('software')) ? $this->input('software') : Software::Custom->value,
            'icon_url' => filled($this->input('icon_url')) ? $this->input('icon_url') : null,
            'description' => filled($this->input('description')) ? $this->input('description') : null,
            'subdomain' => $this->normalisedSubdomain(),
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
            'is_public' => ['boolean'],
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
            'minecraft_version' => ['exclude_if:software,custom', 'required', 'string', 'max:40', 'regex:'.InstallPlan::VERSION_PATTERN],
            'start_command' => ['exclude_unless:software,custom', 'required', 'string', 'max:500', 'not_regex:/[\r\n\0]/'],
            'java_version' => ['exclude_unless:software,custom', 'required', 'integer', Rule::in(config('minecraft.java_versions'))],
            'idle_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'subdomain' => [
                'nullable', 'string', 'max:190', 'regex:'.Server::SUBDOMAIN_PATTERN,
                Rule::unique(Server::class, 'subdomain')->ignore($server instanceof Server ? $server->id : null),
            ],
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
        ];
    }

    /** @var array{java_version: int, start_command: string}|null */
    private ?array $installSettings = null;

    /**
     * The validated attributes to save: the start command and Java worked out for an
     * installed server, and no Minecraft version for a custom one.
     *
     * @return array<string, mixed>
     */
    public function serverAttributes(): array
    {
        $attributes = $this->safe()->except('eula');

        if (($attributes['software'] ?? null) === Software::Custom->value) {
            $attributes['minecraft_version'] = null;
        }

        return [...$attributes, ...($this->installSettings ?? [])];
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
}
