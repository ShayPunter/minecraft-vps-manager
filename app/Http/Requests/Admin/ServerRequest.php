<?php

namespace App\Http\Requests\Admin;

use App\Cloud\CloudException;
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
            'icon_url' => filled($this->input('icon_url')) ? $this->input('icon_url') : null,
            'description' => filled($this->input('description')) ? $this->input('description') : null,
        ]);
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
            'icon_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'server_type' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'location' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            // A shell command line that runs on the server's own VPS (an admin's choice).
            // One line only: a newline would start a second command in the start script.
            'start_command' => ['required', 'string', 'max:500', 'not_regex:/[\r\n\0]/'],
            'java_version' => ['required', 'integer', Rule::in(config('minecraft.java_versions'))],
            'idle_minutes' => ['required', 'integer', 'min:5', 'max:240'],
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
        ];
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
        ];
    }
}
