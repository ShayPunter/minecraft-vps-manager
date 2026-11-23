<?php

namespace App\Enums;

/**
 * Who can see and start a server (#5462).
 */
enum Visibility: string
{
    /** On the home page; anyone may start it. */
    case Public = 'public';

    /** On the dashboard of everyone signed in. */
    case Private = 'private';

    /** Only through its share link (and in Admin); no account needed. */
    case Link = 'link';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private',
            self::Link => 'Link only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Public => 'listed on the home page, anyone can start it',
            self::Private => 'signed-in players see it on their dashboard',
            self::Link => 'only people with its share link see it, no account needed',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $visibility) => ['value' => $visibility->value, 'label' => $visibility->label(), 'description' => $visibility->description()],
            self::cases(),
        );
    }
}
