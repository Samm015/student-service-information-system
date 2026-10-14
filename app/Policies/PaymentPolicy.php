<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_CASHIER);
    }

    /** A student sees their own payment history. */
    public function view(User $user, Payment $payment): bool
    {
        return $user->hasRole(User::ROLE_CASHIER)
            || ($user->hasRole(User::ROLE_STUDENT) && $user->student?->id === $payment->student_id);
    }

    /** Record a payment / issue a receipt / void: cashier only. */
    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_CASHIER);
    }

    public function void(User $user, Payment $payment): bool
    {
        return $user->hasRole(User::ROLE_CASHIER);
    }
}
