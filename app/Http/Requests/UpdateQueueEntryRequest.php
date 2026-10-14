<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Start processing / complete / skip / cancel one queue entry. Route model: {queueEntry}. */
class UpdateQueueEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('queueEntry')) ?? false;
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['start', 'complete', 'skip', 'cancel'])]];
    }
}
