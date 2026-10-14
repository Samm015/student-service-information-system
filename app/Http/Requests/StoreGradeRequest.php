<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use App\Models\Grade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A department user encodes a grade (matches the Department portal form). */
class StoreGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Grade::class) ?? false;
    }

    public function messages(): array
    {
        return [
            'student_no.exists' => 'No student has that student number.',
            'subject_id.required' => 'Choose a subject.',
            'subject_id.exists' => 'That subject does not exist.',
            'grade.required'    => 'Enter a grade.',
            'grade.numeric'     => 'The grade must be a number.',
            'grade.between'     => 'The grade must be between 1.00 and 5.00.',
            'grade.decimal'     => 'Use at most 2 decimal places.',
        ];
    }

    public function rules(): array
    {
        return [
            'student_no'  => ['required', 'string', 'exists:students,student_no'],   // nonexistent student
            'subject_id'  => ['required', 'integer', 'exists:subjects,id'],          // nonexistent subject
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'semester'    => ['required', Rule::in(Enrollment::SEMESTERS)],
            'grade'       => [
                'required', 'numeric', 'decimal:0,2', 'between:1,5',                   // 1.00 - 5.00
                // Grades move in steps of 0.25 (1.00, 1.25, 1.50 ... 5.00).
                fn ($attr, $value, $fail) => fmod(round((float) $value * 100), 25) === 0.0
                    ? null : $fail('Grades go in steps of 0.25 (for example 1.00, 1.25, 1.50).'),
            ],
            'remarks'     => ['nullable', Rule::in(Grade::REMARKS)],
        ];
    }
}
