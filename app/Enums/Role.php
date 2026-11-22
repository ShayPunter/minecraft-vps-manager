<?php

namespace App\Enums;

/**
 * What a user may do. Admins manage users (and, later, servers); players can sign in
 * and start the servers they're allowed to.
 */
enum Role: string
{
    case Admin = 'admin';
    case Player = 'player';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Player => 'Player',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
