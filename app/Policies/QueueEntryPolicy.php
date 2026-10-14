<?php

namespace App\Policies;

use App\Models\QueueEntry;
use App\Models\User;

class QueueEntryPolicy extends BasePolicy
{
    /** Staff who run a window can see the whole queue. */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION, User::ROLE_CASHIER);
    }

    public function view(User $user, QueueEntry $entry): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION, User::ROLE_CASHIER)
            || ($user->hasRole(User::ROLE_STUDENT) && $user->student?->id === $entry->student_id);
    }

    /** Students take a number for themselves. */
    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_STUDENT) && $user->student !== null;
    }

    /**
     * Start / complete / skip / cancel. Registration handles every service except
     * payment; the cashier handles only payment (see QueueEntry::servicesFor()).
     */
    public function manage(User $user, QueueEntry $entry): bool
    {
        return in_array($entry->service, QueueEntry::servicesFor($user), true);
    }

    /** A student may cancel their own entry while it is still waiting. */
    public function cancel(User $user, QueueEntry $entry): bool
    {
        return $user->hasRole(User::ROLE_STUDENT)
            && $user->student?->id === $entry->student_id
            && $entry->status === QueueEntry::STATUS_WAITING;
    }
}
