<?php

namespace App\Http\Requests;

use App\Models\QueueEntry;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Take a queue number.
 *  - A student takes one for themselves (no student_no needed).
 *  - Registration/Cashier staff may take one for a walk-in by sending student_no.
 */
class StoreQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->can('create', QueueEntry::class) || $user->hasRole('registration', 'cashier'));
    }

    public function rules(): array
    {
        return [
            'service'    => ['required', Rule::in(QueueEntry::SERVICES)],
            'student_no' => ['nullable', 'string', 'exists:students,student_no'], // nonexistent student
        ];
    }

    /** Resolve which student the entry is for (call this after validation). */
    public function targetStudent(): ?Student
    {
        $user = $this->user();

        // Students can only queue for themselves, whatever they send.
        if ($user->hasRole('student')) {
            return $user->student;
        }

        return $this->filled('student_no') ? Student::findByNumber($this->input('student_no')) : null;
    }

    /** Extra checks: a student is required, and no duplicate active entry. */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $student = $this->targetStudent();

            // Entries left over from an earlier day must not block a new number.
            if ($student) {
                QueueEntry::expireStale($student->id);
            }

            if (! $student) {
                $validator->errors()->add('student_no', 'A student number is required.');
            } elseif (QueueEntry::hasActive($student->id, $this->input('service'))) {
                $validator->errors()->add('service', 'You already have an active queue number for this service.');
            }
        }];
    }
}
