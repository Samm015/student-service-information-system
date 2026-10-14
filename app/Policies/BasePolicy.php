<?php

namespace App\Policies;

use App\Models\User;

/**
 * All policies extend this. `before()` runs first: admins pass every check,
 * everyone else falls through to the policy's own methods (return null = "keep checking").
 */
abstract class BasePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }
}
