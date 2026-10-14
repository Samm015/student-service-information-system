<?php

namespace App\Http\Requests;

use App\Models\DocumentRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A student requests a document. There is deliberately NO date field: Registration decides the
 * expected release date (see UpdateDocumentReleaseDateRequest). A date sent anyway is ignored.
 */
class StoreDocumentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DocumentRequest::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => [
                'required', 'integer',
                Rule::exists('document_types', 'id')->where('is_active', true), // invalid document type
            ],
            'copies'      => ['required', 'integer', 'between:1,' . DocumentRequest::MAX_COPIES], // invalid copies
            'purpose'     => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type_id.required' => 'Choose a document.',
            'document_type_id.exists'   => 'That document is not available.',
            'copies.between'            => 'Copies must be between 1 and ' . DocumentRequest::MAX_COPIES . '.',
            'purpose.required'          => 'Tell us what the document is for.',
            'purpose.min'               => 'The purpose is too short.',
        ];
    }

    /** Duplicate guard: one active request per document type. */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $student = $this->user()->student;
            if (! $student) {
                return;
            }

            $active = DocumentRequest::where('student_id', $student->id)
                ->where('document_type_id', $this->input('document_type_id'))
                ->whereIn('status', DocumentRequest::ACTIVE_STATUSES)
                ->first();

            if ($active) {
                $validator->errors()->add(
                    'document_type_id',
                    "You already have an active request for this document ({$active->reference_no}). Wait for it to finish or cancel it first."
                );
            }
        }];
    }
}
