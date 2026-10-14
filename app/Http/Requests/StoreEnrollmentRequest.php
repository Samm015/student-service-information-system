<?php

namespace App\Http\Requests;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Subject;
use App\Support\AcademicTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A student submits an enrollment. The student is the logged-in user, never a form field. */
class StoreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'semester'    => ['required', Rule::in(Enrollment::SEMESTERS)],
            'year_level'  => ['required', 'integer', 'between:1,6'],
            'subject_ids'   => ['required', 'array', 'min:1', 'max:12'],
            'subject_ids.*' => [
                'integer', 'distinct',
                Rule::exists('subjects', 'id')->where('is_active', true), // nonexistent / inactive subject
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'subject_ids.required'  => 'Select at least one subject.',
            'subject_ids.min'       => 'Select at least one subject.',
            'subject_ids.max'       => 'You can enroll in at most 12 subjects.',
            'subject_ids.*.exists'  => 'One of the selected subjects is not available.',
            'subject_ids.*.distinct' => 'A subject was selected twice.',
        ];
    }

    /** Extra checks that need the database. */
    public function after(): array
    {
        return [function ($validator) {
            // Only run these once the basic rules passed.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $student = $this->user()->student;
            if (! $student) {
                return; // admins have no student profile; the controller refuses them
            }

            // 1. Only the current term and the next one are open.
            if (! AcademicTerm::isEnrollable($this->input('school_year'), $this->input('semester'))) {
                $validator->errors()->add('semester', 'Enrollment is not open for that school year and semester.');
                return;
            }

            // 2. One enrollment per term. A rejected/cancelled one may be re-submitted.
            $existing = Enrollment::where('student_id', $student->id)
                ->where('school_year', $this->input('school_year'))
                ->where('semester', $this->input('semester'))
                ->first();

            if ($existing?->status === Enrollment::STATUS_APPROVED) {
                $validator->errors()->add('semester', 'Your enrollment for this term is already approved.');
                return;
            }
            if ($existing?->status === Enrollment::STATUS_PENDING) {
                $validator->errors()->add('semester', 'You already submitted an enrollment for this term. It is waiting for review.');
                return;
            }

            // 3. Do not re-enroll in a subject the student already passed.
            $passed = Grade::where('student_id', $student->id)
                ->passed()
                ->whereIn('subject_id', $this->input('subject_ids'))
                ->pluck('subject_id');

            if ($passed->isNotEmpty()) {
                $codes = Subject::whereIn('id', $passed)->orderBy('code')->pluck('code')->implode(', ');
                $validator->errors()->add('subject_ids', "You already passed: {$codes}. Remove it from your selection.");
            }
        }];
    }
}
