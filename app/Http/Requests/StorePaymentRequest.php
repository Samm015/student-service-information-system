<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The cashier records a walk-in payment by student number (Cashier portal form).
 * Document fees are NOT recorded here: they are charged when Registration accepts a
 * document request and are settled from the "Pending payments" list.
 */
class StorePaymentRequest extends FormRequest
{
    public const WALK_IN_FEE_TYPES = ['tuition', 'miscellaneous', 'clearance_fee'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'student_no' => ['required', 'string', 'exists:students,student_no'],
            'fee_type'   => ['required', Rule::in(self::WALK_IN_FEE_TYPES)],
            'amount'     => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000'],
            'paid_on'    => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:-30 days'],
            'remarks'    => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_no.exists'       => 'No student has that student number.',
            'amount.gt'               => 'The amount must be greater than zero.',
            'amount.decimal'          => 'Use at most 2 decimal places.',
            'amount.max'              => 'That amount is too large.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
            'paid_on.after_or_equal'  => 'The payment date cannot be older than 30 days.',
        ];
    }
}
