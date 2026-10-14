<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registration approves or rejects an enrollment. The route model is bound as {enrollment}. */
class ReviewEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('enrollment')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status'  => ['required', Rule::in(['approved', 'rejected'])], // invalid status
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ];
    }
}
