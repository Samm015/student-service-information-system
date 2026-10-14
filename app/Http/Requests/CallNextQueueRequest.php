<?php

namespace App\Http\Requests;

use App\Models\QueueEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Staff calls the next waiting person to a window. */
class CallNextQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registration (all services except payment) and Cashier (payment) run windows.
        $user = $this->user();

        return $user !== null && QueueEntry::servicesFor($user) !== [];
    }

    public function rules(): array
    {
        $allowed = QueueEntry::servicesFor($this->user());

        return [
            'counter' => ['required', 'string', Rule::in(config('school.counters', []))],
            // Empty = "any service this window handles".
            'service' => ['nullable', 'string', Rule::in($allowed)],
        ];
    }

    public function messages(): array
    {
        return [
            'counter.required' => 'Choose the window you are calling from.',
            'counter.in'       => 'That window does not exist.',
            'service.in'       => 'You cannot serve that service from this portal.',
        ];
    }
}
