<?php

namespace App\Support;

use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * What the Admin portal can manage besides users: Departments, Subjects and Document Types.
 * AdminCatalogController reads these descriptions to list, create and edit rows, and the React
 * page draws its table and form from the same description, so adding a field means editing one array.
 *
 * Nothing is ever deleted: a row is switched off ("inactive") so old requests, grades and
 * enrollments keep pointing at something real.
 */
class AdminCatalog
{
    public const RESOURCES = ['departments', 'subjects', 'document-types'];

    public static function make(string $resource): array
    {
        return match ($resource) {
            'departments'    => self::departments(),
            'subjects'       => self::subjects(),
            'document-types' => self::documentTypes(),
            default          => abort(404),
        };
    }

    private static function departments(): array
    {
        return [
            'key'         => 'department',
            'model'       => Department::class,
            'title'       => 'Departments',
            'singular'    => 'department',
            'description' => 'Academic departments and service offices (Cashier, Registrar…). Staff accounts and clearance requirements belong to one.',
            'search'      => ['code', 'name'],
            'order'       => 'code',
            'withCount'   => ['staff', 'students', 'subjects'],
            'filter'      => ['name' => 'type', 'label' => 'All types', 'column' => 'type', 'options' => [
                ['value' => Department::TYPE_ACADEMIC, 'label' => 'Academic'],
                ['value' => Department::TYPE_OFFICE, 'label' => 'Office'],
            ]],
            'columns' => [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'type_label', 'label' => 'Type'],
                ['key' => 'usage', 'label' => 'In use by'],
            ],
            'fields' => [
                ['name' => 'code', 'label' => 'Code', 'input' => 'code', 'required' => true, 'hint' => 'Short and unique, e.g. CCS.'],
                ['name' => 'name', 'label' => 'Name', 'input' => 'any', 'required' => true],
                ['name' => 'type', 'label' => 'Type', 'input' => 'select', 'required' => true, 'options' => [
                    ['value' => Department::TYPE_ACADEMIC, 'label' => 'Academic department'],
                    ['value' => Department::TYPE_OFFICE, 'label' => 'Service office'],
                ]],
            ],
            'rules' => fn (?Model $m) => [
                'code' => ['required', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($m?->getKey())],
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', Rule::in([Department::TYPE_ACADEMIC, Department::TYPE_OFFICE])],
            ],
            'row' => fn (Department $d) => [
                'code'       => $d->code,
                'name'       => $d->name,
                'type'       => $d->type,
                'type_label' => $d->type === Department::TYPE_OFFICE ? 'Office' : 'Academic',
                'usage'      => "{$d->staff_count} staff · {$d->students_count} students · {$d->subjects_count} subjects",
            ],
        ];
    }

    private static function subjects(): array
    {
        $owners = Department::where('is_active', true)->where('type', Department::TYPE_ACADEMIC)
            ->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($d) => ['value' => $d->id, 'label' => "{$d->code} · {$d->name}", 'short' => $d->code])->all();

        return [
            'key'         => 'subject',
            'model'       => Subject::class,
            'title'       => 'Subjects',
            'singular'    => 'subject',
            'description' => 'The subject catalogue students enroll in. The owning department is the one that encodes its grades.',
            'search'      => ['code', 'title'],
            'order'       => 'code',
            'with'        => ['department:id,code'],
            'filter'      => ['name' => 'department_id', 'label' => 'All departments', 'column' => 'department_id', 'options' => collect($owners)->map(
                fn ($o) => ['value' => $o['value'], 'label' => $o['short']]
            )->all()],
            'columns' => [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'title', 'label' => 'Title'],
                ['key' => 'units', 'label' => 'Units'],
                ['key' => 'department_code', 'label' => 'Department'],
            ],
            'fields' => [
                ['name' => 'code', 'label' => 'Code', 'input' => 'code', 'required' => true, 'hint' => 'e.g. ITP101.'],
                ['name' => 'title', 'label' => 'Title', 'input' => 'any', 'required' => true],
                ['name' => 'units', 'label' => 'Units', 'input' => 'number', 'required' => true, 'hint' => 'Whole number, 1 to 6.'],
                ['name' => 'department_id', 'label' => 'Owning department', 'input' => 'select', 'required' => true,
                    'options' => collect($owners)->map(fn ($o) => ['value' => $o['value'], 'label' => $o['label']])->all()],
            ],
            'rules' => fn (?Model $m) => [
                'code'          => ['required', 'string', 'max:20', Rule::unique('subjects', 'code')->ignore($m?->getKey())],
                'title'         => ['required', 'string', 'max:255'],
                'units'         => ['required', 'integer', 'between:1,6'],
                'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('type', Department::TYPE_ACADEMIC)->where('is_active', true)],
            ],
            'row' => fn (Subject $s) => [
                'code'            => $s->code,
                'title'           => $s->title,
                'units'           => $s->units,
                'department_id'   => $s->department_id,
                'department_code' => $s->department?->code,
            ],
        ];
    }

    private static function documentTypes(): array
    {
        return [
            'key'         => 'document_type',
            'model'       => DocumentType::class,
            'title'       => 'Document Types',
            'singular'    => 'document type',
            'description' => 'The documents students can request. Changing a fee affects new requests only: existing requests keep the fee they were charged.',
            'search'      => ['code', 'name'],
            'order'       => 'name',
            'columns' => [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Document'],
                ['key' => 'fee_label', 'label' => 'Fee per copy'],
                ['key' => 'processing_days', 'label' => 'Working days'],
            ],
            'fields' => [
                ['name' => 'code', 'label' => 'Code', 'input' => 'code', 'required' => true, 'hint' => 'e.g. TOR.'],
                ['name' => 'name', 'label' => 'Name', 'input' => 'any', 'required' => true],
                ['name' => 'fee', 'label' => 'Fee per copy (₱)', 'input' => 'number', 'required' => true, 'hint' => 'Use 0 for a free document.'],
                ['name' => 'processing_days', 'label' => 'Processing time (working days)', 'input' => 'number', 'required' => true],
            ],
            'rules' => fn (?Model $m) => [
                'code'            => ['required', 'string', 'max:20', Rule::unique('document_types', 'code')->ignore($m?->getKey())],
                'name'            => ['required', 'string', 'max:255'],
                'fee'             => ['required', 'numeric', 'min:0', 'max:100000', 'regex:/^\d+(\.\d{1,2})?$/'],
                'processing_days' => ['required', 'integer', 'between:1,60'],
            ],
            'row' => fn (DocumentType $t) => [
                'code'            => $t->code,
                'name'            => $t->name,
                'fee'             => number_format((float) $t->fee, 2, '.', ''),
                'fee_label'       => '₱' . number_format((float) $t->fee, 2),
                'processing_days' => $t->processing_days,
            ],
        ];
    }
}
