<?php

namespace App\Http\Requests;

use App\Models\DocumentRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Registration moves a document request along. Route model is bound as {documentRequest}. */
class UpdateDocumentRequestStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('process', $this->route('documentRequest')) ?? false;
    }

    public function rules(): array
    {
        return [
            // Staff may only set these. pending / awaiting_payment / cancelled are set by the
            // system or by the student; "processing" on a pending request means "accept it".
            'status'  => ['required', Rule::in(['processing', 'ready', 'released', 'rejected'])],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ];
    }
}
