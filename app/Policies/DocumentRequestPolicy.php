<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;

class DocumentRequestPolicy extends BasePolicy
{
    /** Registration processes requests; Cashier needs to see what a payment is for. */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION, User::ROLE_CASHIER);
    }

    public function view(User $user, DocumentRequest $request): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION, User::ROLE_CASHIER)
            || ($user->hasRole(User::ROLE_STUDENT) && $user->student?->id === $request->student_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_STUDENT) && $user->student !== null;
    }

    /** Move a request through processing / ready / released / rejected. */
    public function process(User $user, DocumentRequest $request): bool
    {
        return $user->hasRole(User::ROLE_REGISTRATION);
    }

    /** A student may cancel their own request while it is still pending. */
    public function cancel(User $user, DocumentRequest $request): bool
    {
        return $user->hasRole(User::ROLE_STUDENT)
            && $user->student?->id === $request->student_id
            && in_array($request->status, [DocumentRequest::STATUS_PENDING, DocumentRequest::STATUS_AWAITING_PAYMENT], true);
    }
}
