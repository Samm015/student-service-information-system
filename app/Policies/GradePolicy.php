<?php

namespace App\Policies;

use App\Models\Grade;
use App\Models\User;

class GradePolicy extends BasePolicy
{
    /** Students see their own grades; Registration may look up any grade. */
    public function view(User $user, Grade $grade): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION)
            || ($user->hasRole(User::ROLE_STUDENT) && $user->student?->id === $grade->student_id)
            || $this->ownsSubject($user, $grade);
    }

    /** Any department user may open the grade-encoding screen. */
    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_DEPARTMENT);
    }

    /** Edit a grade: only the department that owns the subject. */
    public function update(User $user, Grade $grade): bool
    {
        return $this->ownsSubject($user, $grade);
    }

    private function ownsSubject(User $user, Grade $grade): bool
    {
        return $user->hasRole(User::ROLE_DEPARTMENT)
            && $user->department_id !== null
            && $user->department_id === $grade->subject->department_id;
    }
}
