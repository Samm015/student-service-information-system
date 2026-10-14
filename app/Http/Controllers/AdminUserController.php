<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Student;
use App\Models\User;
use App\Support\StaffPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Admin: accounts. Search, filter by role, create, edit, activate / deactivate.
 * Accounts are never deleted (students keep their history), only deactivated.
 * The routes sit behind role:admin.
 */
class AdminUserController extends Controller
{
    private const STAFF_ROLES = [User::ROLE_REGISTRATION, User::ROLE_CASHIER, User::ROLE_DEPARTMENT];

    public function index(Request $request, StaffPortal $staff)
    {
        $q      = StaffPortal::searchText($request);
        $role   = StaffPortal::choice($request, 'role', array_merge(['all'], User::ROLES), 'all');
        $status = StaffPortal::choice($request, 'status', ['all', 'active', 'inactive'], 'all');

        $page = User::query()
            ->with(['department:id,code,name', 'student:id,user_id,student_no,department_id,program,year_level'])
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('student', fn ($s) => $s->where('student_no', 'like', "%{$q}%"))))
            ->when($role !== 'all', fn ($x) => $x->where('role', $role))
            ->when($status === 'active', fn ($x) => $x->where('is_active', true))
            ->when($status === 'inactive', fn ($x) => $x->where('is_active', false))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $me = $request->user()->id;

        return Inertia::render('Admin/Users', [
            'filters' => ['q' => $q, 'role' => $role, 'status' => $status],
            'rows'    => $page->getCollection()->map(fn (User $u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'email'         => $u->email,
                'role'          => $u->role,
                'department'    => $u->department?->code,
                'department_id' => $u->department_id ?? $u->student?->department_id,
                'student_no'    => $u->student?->student_no,
                'program'       => $u->student?->program,
                'year_level'    => $u->student?->year_level,
                'is_active'     => (bool) $u->is_active,
                'is_self'       => $u->id === $me,
                'update_url'    => route('admin.users.update', $u, false),
                'status_url'    => route('admin.users.status', $u, false),
            ])->values(),
            'pager'   => $staff->pager($page),
            'options' => [
                'roles' => collect(User::ROLES)
                    ->map(fn ($r) => ['value' => $r, 'label' => ucfirst($r)])
                    ->values(),

                'departments' => Department::where('is_active', true)
                    ->orderBy('code')
                    ->get(['id', 'code', 'name', 'type'])
                    ->map(fn ($d) => [
                        'value' => $d->id,
                        'label' => "{$d->code} · {$d->name}",
                        'type' => $d->type,
                    ])
                    ->values(),

                'programs' => collect(config('school.programs', []))
                    ->map(fn ($program) => [
                        'value' => $program,
                        'label' => $program,
                    ])
                    ->values(),

                'store_url' => route('admin.users.store', [], false),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'role'     => ['required', Rule::in(User::ROLES)],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            ...$this->profileRules($request, null),
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name'          => $data['name'],
                'email'         => $data['email'],
                'role'          => $data['role'],
                'password'      => $data['password'], // hashed by the model cast
                'department_id' => in_array($data['role'], self::STAFF_ROLES, true) ? $data['department_id'] : null,
                'is_active'     => true,
            ]);

            if ($data['role'] === User::ROLE_STUDENT) {
                Student::create([
                    'user_id'       => $user->id,
                    'student_no'    => $data['student_no'],
                    'department_id' => $data['department_id'],
                    'program'       => $data['program'],
                    'year_level'    => $data['year_level'],
                    'status'        => 'active',
                ]);
            }

            return $user;
        });

        AuditLog::record('user.created', $user, null, ['email' => $user->email, 'role' => $user->role]);

        return back()->with('success', "Account for {$user->name} was created.");
    }

    public function update(Request $request, User $user)
    {
        $isSelf    = $user->id === $request->user()->id;
        $isStudent = $user->role === User::ROLE_STUDENT;

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'     => ['required', Rule::in(User::ROLES)],
            'password' => ['nullable', 'string', 'min:8', 'max:100'], // empty = keep the current password
            ...$this->profileRules($request, $user),
        ]);

        // A student stays a student and nobody else becomes one (a student needs a student profile).
        if ($data['role'] !== $user->role && ($isStudent || $data['role'] === User::ROLE_STUDENT)) {
            throw ValidationException::withMessages(['role' => 'The role of a student account cannot be changed.']);
        }
        // Admins cannot lock themselves out by changing their own role.
        if ($isSelf && $data['role'] !== $user->role) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        $old = $user->only('name', 'email', 'role', 'department_id');

        DB::transaction(function () use ($user, $data, $isStudent) {
            $user->fill([
                'name'          => $data['name'],
                'email'         => $data['email'],
                'role'          => $data['role'],
                'department_id' => in_array($data['role'], self::STAFF_ROLES, true) ? $data['department_id'] : null,
            ]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();

            if ($isStudent && $user->student) {
                $user->student->update([
                    'student_no'    => $data['student_no'],
                    'department_id' => $data['department_id'],
                    'program'       => $data['program'],
                    'year_level'    => $data['year_level'],
                ]);
            }
        });

        AuditLog::record('user.updated', $user, $old, $user->only('name', 'email', 'role', 'department_id') + [
            'password_changed' => ! empty($data['password']),
        ]);

        return back()->with('success', "Account for {$user->name} was updated.");
    }

    /** Switch an account on or off. */
    public function status(Request $request, User $user)
    {
        $data   = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];

        if ($user->id === $request->user()->id && ! $active) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => $active]);
        AuditLog::record($active ? 'user.activated' : 'user.deactivated', $user);

        return back()->with('success', $active
            ? "{$user->name} can log in again."
            : "{$user->name} was deactivated and can no longer log in.");
    }

    /** Fields that depend on the role: staff need an office, students need a student profile. */
    private function profileRules(Request $request, ?User $existing): array
    {
        $role      = $request->input('role', $existing?->role);
        $studentId = $existing?->student?->id;

        return [
            'department_id' => [
                Rule::requiredIf(fn () => in_array($role, [...self::STAFF_ROLES, User::ROLE_STUDENT], true)),
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            'student_no' => [
                Rule::requiredIf(fn () => $role === User::ROLE_STUDENT),
                'nullable', 'string', 'max:20', 'regex:/^\d{4}-\d{5}$/',
                Rule::unique('students', 'student_no')->ignore($studentId),
            ],
            'program' => [
                Rule::requiredIf(fn () => $role === User::ROLE_STUDENT),
                'nullable',
                'string',
                'max:20',
                Rule::in(config('school.programs', [])),
            ],
            'year_level' => [Rule::requiredIf(fn () => $role === User::ROLE_STUDENT), 'nullable', 'integer', 'between:1,4'],
        ];
    }
}
