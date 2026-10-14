<?php

namespace App\Policies;

use App\Models\ClearanceItem;
use App\Models\User;

class ClearanceItemPolicy extends BasePolicy
{
    /** Students see their own clearance; the responsible office sees its items. */
    public function view(User $user, ClearanceItem $item): bool
    {
        return $this->belongsToOffice($user, $item)
            || ($user->hasRole(User::ROLE_STUDENT) && $user->student?->id === $item->clearance->student_id);
    }

    /**
     * Sign / reject an item: only a staff member whose department/office is
     * the one responsible for that item (Library staff sign Library items, etc.).
     */
    public function sign(User $user, ClearanceItem $item): bool
    {
        return $this->belongsToOffice($user, $item);
    }

    private function belongsToOffice(User $user, ClearanceItem $item): bool
    {
        return ! $user->hasRole(User::ROLE_STUDENT)
            && $user->department_id !== null
            && $user->department_id === $item->department_id;
    }
}
