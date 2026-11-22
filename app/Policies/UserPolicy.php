<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may manage accounts. Sign-up is off, so admins create every account.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Admins can delete anyone but themselves, so the panel can't lock its last admin out.
     */
    public function delete(User $actor, User $user): bool
    {
        return $actor->isAdmin() && ! $actor->is($user);
    }
}
