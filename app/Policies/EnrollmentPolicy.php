<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy extends BasePolicy
{
    /** Registration sees the whole list. */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION);
    }

    /** A student sees only their own enrollment; Registration sees all. */
    public function view(User $user, Enrollment $enrollment): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION)
            || ($user->hasRole(User::ROLE_STUDENT) && $user->student?->id === $enrollment->student_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_STUDENT) && $user->student !== null;
    }

    /** Approve / reject. */
    public function review(User $user, Enrollment $enrollment): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION);
    }
}
