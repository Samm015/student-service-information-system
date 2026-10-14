<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

/** The cashier records the money received for an existing PENDING payment. Route model: {payment}. */
class SettlePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Payment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'amount'  => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:-30 days'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required'         => 'Enter the amount received.',
            'amount.gt'               => 'The amount must be greater than zero.',
            'amount.decimal'          => 'Use at most 2 decimal places.',
            'paid_on.before_or_equal' => 'The payment date cannot be in the future.',
            'paid_on.after_or_equal'  => 'The payment date cannot be older than 30 days.',
        ];
    }
}
