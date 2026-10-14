<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The cashier voids a payment. A reason is mandatory. Route model: {payment}. */
class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('payment')) ?? false;
    }

    public function rules(): array
    {
        return ['remarks' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function messages(): array
    {
        return ['remarks.required' => 'Give a reason for voiding this payment.'];
    }
}
