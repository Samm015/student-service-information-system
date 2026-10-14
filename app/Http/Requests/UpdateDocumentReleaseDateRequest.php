<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registration sets (or changes) the expected release date of a document request.
 * Only users the DocumentRequestPolicy lets "process" requests pass authorize():
 * a student, the cashier or a department user gets a 403 before anything is read.
 */
class UpdateDocumentReleaseDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('process', $this->route('documentRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_release_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_release_date.required'       => 'Choose the expected release date.',
            'expected_release_date.date'           => 'That is not a valid date.',
            'expected_release_date.after_or_equal' => 'The release date cannot be in the past.',
        ];
    }
}
