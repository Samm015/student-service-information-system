<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An office signs or rejects a clearance item. Route model is bound as {clearanceItem}. */
class SignClearanceItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sign', $this->route('clearanceItem')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::in(['signed', 'rejected'])],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ];
    }
}
