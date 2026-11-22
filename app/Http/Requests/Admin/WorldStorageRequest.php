<?php

namespace App\Http\Requests\Admin;

use App\Models\WorldStorageSetting;
use App\WorldStorage\StorageSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * Admin → World storage (#5288): test or save the settings. An empty password or key field
 * keeps the saved one (they are never sent to the browser); the "remove" boxes drop it.
 */
class WorldStorageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $privateKey = $this->input('private_key');

        $this->merge([
            'host' => is_string($this->input('host')) ? trim($this->input('host')) : $this->input('host'),
            'username' => is_string($this->input('username')) ? trim($this->input('username')) : $this->input('username'),
            'root' => is_string($this->input('root')) ? trim(trim($this->input('root')), '/') : $this->input('root'),
            'password' => filled($this->input('password')) ? $this->input('password') : null,
            'private_key' => is_string($privateKey) && trim($privateKey) !== '' ? str_replace("\r\n", "\n", trim($privateKey))."\n" : null,
            'ftps' => $this->boolean('ftps'),
            'remove_password' => $this->boolean('remove_password'),
            'remove_private_key' => $this->boolean('remove_private_key'),
            'trust_new_host_key' => $this->boolean('trust_new_host_key'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'driver' => ['required', Rule::in(StorageSettings::DRIVERS)],
            'host' => ['required', 'string', 'max:253', 'regex:'.StorageSettings::HOST_PATTERN],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'regex:'.StorageSettings::USERNAME_PATTERN],
            'password' => ['nullable', 'string', 'max:500', 'not_regex:/[\r\n\0]/'],
            'private_key' => ['nullable', 'string', 'max:20000'],
            'root' => ['required', 'string', 'max:200', 'regex:'.StorageSettings::ROOT_PATTERN, 'not_regex:/(^|\/)\.{1,2}(\/|$)/'],
            'ftps' => ['boolean'],
            'remove_password' => ['boolean'],
            'remove_private_key' => ['boolean'],
            'trust_new_host_key' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'host.regex' => 'Enter a host name or IPv4 address, like u123456.your-storagebox.de.',
            'username.regex' => 'Use letters, digits, dots, dashes and underscores only.',
            'root.regex' => 'A folder path like minecraft-worlds or backups/minecraft: letters, digits, dots, dashes and underscores.',
            'root.not_regex' => 'The base folder can\'t contain "." or ".." parts.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $key = $this->input('private_key');

                if (is_string($key)) {
                    if ($this->input('driver') !== 'sftp') {
                        $validator->errors()->add('private_key', 'A private key only works with SFTP.');

                        return;
                    }

                    try {
                        $loaded = PublicKeyLoader::load($key);
                    } catch (Throwable) {
                        $loaded = null;
                    }

                    if (! $loaded instanceof PrivateKey) {
                        $validator->errors()->add('private_key', 'That isn\'t an unencrypted OpenSSH or PEM private key.');

                        return;
                    }
                }

                $settings = $this->settings();

                if ($settings->driver === 'sftp' && $settings->password === null && $settings->privateKey === null) {
                    $validator->errors()->add('password', 'SFTP needs a password or a private key.');
                }

                if ($settings->driver === 'ftp' && $settings->password === null) {
                    $validator->errors()->add('password', 'FTP needs a password.');
                }
            },
        ];
    }

    /**
     * The submitted settings, with the saved secrets filled in where a field was left empty.
     * The host key is left out: the connection test supplies it.
     */
    public function settings(): StorageSettings
    {
        $saved = WorldStorageSetting::query()->first();
        $driver = (string) $this->input('driver');

        return new StorageSettings(
            driver: $driver,
            host: (string) $this->input('host'),
            port: (int) $this->input('port'),
            username: (string) $this->input('username'),
            password: $this->boolean('remove_password') ? null : ($this->stringOrNull('password') ?? $this->savedSecret($saved, 'password')),
            privateKey: $driver !== 'sftp' || $this->boolean('remove_private_key')
                ? null
                : ($this->stringOrNull('private_key') ?? $this->savedSecret($saved, 'private_key')),
            root: (string) $this->input('root'),
            ftps: $this->boolean('ftps'),
            hostKey: null,
        );
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }

    private function savedSecret(?WorldStorageSetting $saved, string $attribute): ?string
    {
        if ($saved === null) {
            return null;
        }

        try {
            $value = $saved->getAttribute($attribute);
        } catch (DecryptException) {
            // APP_KEY changed since it was saved: as good as not set, so it has to be entered again.
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
