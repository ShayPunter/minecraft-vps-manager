<?php

namespace App\Servers;

use RuntimeException;

/**
 * A Start that can't go ahead. The message is shown to the person who clicked.
 */
class StartRefused extends RuntimeException
{
    public static function alreadyRunning(string $name): self
    {
        return new self("{$name} is already running or starting.");
    }

    public static function capReached(int $cap): self
    {
        return new self("{$cap} servers are already running, the most allowed at once. Try again when one has stopped.");
    }

    public static function guestBudget(string $name, int $perDay): self
    {
        if ($perDay === 0) {
            return new self("Sign in to start {$name}: guests can't start servers.");
        }

        $times = $perDay === 1 ? 'once' : "{$perDay} times";

        return new self("{$name} has been started by guests {$times} today, the most allowed. Sign in to start it again today.");
    }

    public static function guestSlot(string $name): self
    {
        return new self("Sign in to start {$name} now: the last free server slot is kept for signed-in players. Or try again when another server has stopped.");
    }

    public static function noWorldStorage(): self
    {
        return new self("Servers can't start until world storage is set up (an admin does that in Admin → World storage), because the world couldn't be saved when it stops.");
    }

    public static function busy(): self
    {
        return new self('Another server is starting right now. Try again in a few seconds.');
    }
}
