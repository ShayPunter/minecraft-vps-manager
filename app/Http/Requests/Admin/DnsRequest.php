<?php

namespace App\Http\Requests\Admin;

use App\Dns\Dns;
use App\Dns\DnsZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Admin → DNS (#5338). An empty token field keeps the saved token (it's never sent to the browser).
 */
class DnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $domain = $this->input('domain');
        $zoneId = $this->input('zone_id');
        $token = $this->input('api_token');
        $waker = $this->input('waker_ipv4');

        $this->merge([
            'domain' => is_string($domain) ? rtrim(strtolower(trim($domain)), '.') : $domain,
            'zone_id' => is_string($zoneId) ? strtolower(trim($zoneId)) : $zoneId,
            'api_token' => is_string($token) && trim($token) !== '' ? trim($token) : null,
            'waker_ipv4' => is_string($waker) && trim($waker) !== '' ? trim($waker) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:'.DnsZone::DOMAIN_PATTERN],
            'zone_id' => ['required', 'string', 'regex:'.DnsZone::ZONE_ID_PATTERN],
            'api_token' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Where servers:waker listens (#5620): the public IPv4 of the app's server.
            'waker_ipv4' => ['nullable', 'string', 'ipv4'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter the domain as Cloudflare shows it, like example.com.',
            'zone_id.regex' => 'The Zone ID is 32 letters and digits, on the domain\'s Overview page in Cloudflare.',
            'api_token.regex' => 'That doesn\'t look like a Cloudflare API token.',
            'waker_ipv4.ipv4' => 'Enter an IPv4 address, like 203.0.113.10.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if ($this->zone() === null) {
                    $validator->errors()->add('api_token', 'Paste the API token.');
                }

                $waker = $this->input('waker_ipv4');

                if (is_string($waker) && filter_var($waker, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    $validator->errors()->add('waker_ipv4', 'Use the public address players can reach: this one is private or reserved.');
                }
            },
        ];
    }

    /**
     * The form's zone, with the saved token where the field was left empty.
     */
    public function zone(): ?DnsZone
    {
        $token = $this->input('api_token');

        if (! is_string($token)) {
            try {
                $token = app(Dns::class)->saved()?->getAttribute('api_token');
            } catch (DecryptException) {
                $token = null;
            }
        }

        return is_string($token) ? new DnsZone((string) $this->input('domain'), (string) $this->input('zone_id'), $token) : null;
    }
}
