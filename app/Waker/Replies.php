<?php

namespace App\Waker;

/**
 * What the waker says (#5620). WakerReplies answers from the app's servers; tests use their own.
 */
interface Replies
{
    /**
     * The server-list entry for the address the player typed: the status JSON
     * (version, players, description, favicon).
     *
     * @return array<string, mixed>
     */
    public function status(string $host): array;

    /**
     * Someone tried to join: start the server if it should be, and say what happened.
     * The player name is whatever the client sent (not checked with Mojang).
     */
    public function join(string $host, ?string $player, string $ip): string;
}
