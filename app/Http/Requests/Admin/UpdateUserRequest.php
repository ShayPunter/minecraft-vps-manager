<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && ($this->user()?->can('update', $user) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'role' => [
                'required',
                Rule::enum(Role::class),
                // An admin can't demote themselves, so the panel always keeps an admin.
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if ($this->user()?->is($user) && $value !== Role::Admin->value) {
                        $fail('You can\'t remove your own admin role.');
                    }
                },
            ],
            // Leave blank to keep the current password.
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
        ];
    }
}
