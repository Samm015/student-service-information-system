<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\AdminCatalog;
use App\Support\StaffPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Departments, Subjects and Document Types (see AdminCatalog for what each one looks like).
 * The routes sit behind role:admin, so no other role reaches this controller.
 */
class AdminCatalogController extends Controller
{
    public function index(Request $request, string $resource, StaffPortal $staff)
    {
        $def    = AdminCatalog::make($resource);
        $q      = StaffPortal::searchText($request);
        $status = StaffPortal::choice($request, 'status', ['all', 'active', 'inactive'], 'all');
        $allowedExtra = collect($def['filter']['options'] ?? [])->pluck('value')->map(fn ($v) => (string) $v)->all();
        $filterName   = $def['filter']['name'] ?? null;
        // Accept the filter under its own name (?type=office); keep ?extra= working for the existing page.
        $extra = $filterName ? (string) ($request->query($filterName) ?? $request->query('extra', '')) : '';
        $extra = in_array($extra, $allowedExtra, true) ? $extra : '';

        $page = $def['model']::query()
            ->with($def['with'] ?? [])
            ->withCount($def['withCount'] ?? [])
            ->when($q !== '', fn ($x) => $x->where(function ($w) use ($def, $q) {
                foreach ($def['search'] as $column) {
                    $w->orWhere($column, 'like', "%{$q}%");
                }
            }))
            ->when($status === 'active', fn ($x) => $x->where('is_active', true))
            ->when($status === 'inactive', fn ($x) => $x->where('is_active', false))
            ->when($extra !== '', fn ($x) => $x->where($def['filter']['column'], $extra))
            ->orderBy($def['order'])
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/Catalog', [
            'resource' => $resource,
            'config'   => [
                'title'       => $def['title'],
                'singular'    => $def['singular'],
                'description' => $def['description'],
                'columns'     => $def['columns'],
                'fields'      => $def['fields'],
                'filter'      => isset($def['filter']) ? [
                    'label' => $def['filter']['label'], 'options' => $def['filter']['options'],
                ] : null,
                'store_url'   => route('admin.catalog.store', $resource, false),
            ],
            // The extra filter travels under its own name (?type=office, ?department_id=3).
            'filters' => ['q' => $q, 'status' => $status] + (isset($def['filter']) ? [$def['filter']['name'] => $extra] : []),
            'rows'    => $page->getCollection()->map(fn ($m) => ($def['row'])($m) + [
                'id'         => $m->getKey(),
                'is_active'  => (bool) $m->is_active,
                'update_url' => route('admin.catalog.update', [$resource, $m->getKey()], false),
            ])->values(),
            'pager'   => $staff->pager($page),
        ]);
    }

    public function store(Request $request, string $resource)
    {
        $def = AdminCatalog::make($resource);
        $this->normalize($request);

        $data = $request->validate($def['rules'](null) + ['is_active' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active', true);

        $model = $def['model']::create($data);
        AuditLog::record("{$def['key']}.created", $model, null, $data);

        return back()->with('success', ucfirst($def['singular']) . " “{$this->label($model)}” was added.");
    }

    public function update(Request $request, string $resource, int $id)
    {
        $def   = AdminCatalog::make($resource);
        $model = $def['model']::findOrFail($id);
        $this->normalize($request);

        $data = $request->validate($def['rules']($model) + ['is_active' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active', (bool) $model->is_active);

        $old = $model->only(array_keys($data));
        $model->update($data);
        AuditLog::record("{$def['key']}.updated", $model, $old, $data);

        return back()->with('success', ucfirst($def['singular']) . " “{$this->label($model)}” was updated.");
    }

    /** Codes are stored in capitals without stray spaces, so "ccs " and "CCS" cannot both exist. */
    private function normalize(Request $request): void
    {
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }
    }

    private function label($model): string
    {
        return (string) ($model->code ?? $model->name ?? $model->getKey());
    }
}
