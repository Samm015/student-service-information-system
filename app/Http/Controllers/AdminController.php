<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\QueueEntry;
use App\Models\Subject;
use App\Models\User;
use Inertia\Inertia;

/** Admin home. Every number is counted from the database when the page is opened. */
class AdminController extends Controller
{
    public function __invoke()
    {
        $byRole = User::query()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');

        return Inertia::render('Admin', [
            'stats' => [
                'users_active'        => User::where('is_active', true)->count(),
                'users_inactive'      => User::where('is_active', false)->count(),
                'pending_enrollments' => Enrollment::where('status', Enrollment::STATUS_PENDING)->count(),
                'open_documents'      => DocumentRequest::whereIn('status', DocumentRequest::ACTIVE_STATUSES)->count(),
                'pending_payments'    => Payment::where('status', Payment::STATUS_PENDING)->count(),
                'queue_waiting'       => QueueEntry::whereDate('queue_date', today())->where('status', QueueEntry::STATUS_WAITING)->count(),
            ],
            'roles' => collect(User::ROLES)->map(fn ($r) => [
                'role' => $r, 'total' => (int) ($byRole[$r] ?? 0),
            ])->values(),
            'sections' => [
                ['key' => 'users', 'title' => 'Users', 'href' => '/admin/users', 'count' => User::count(), 'note' => 'Accounts and roles', 'icon' => 'users'],
                ['key' => 'departments', 'title' => 'Departments', 'href' => '/admin/departments', 'count' => Department::count(), 'note' => 'Departments and offices', 'icon' => 'building'],
                ['key' => 'subjects', 'title' => 'Subjects', 'href' => '/admin/subjects', 'count' => Subject::count(), 'note' => 'Subject catalogue', 'icon' => 'book'],
                ['key' => 'document-types', 'title' => 'Document Types', 'href' => '/admin/document-types', 'count' => DocumentType::count(), 'note' => 'Requestable documents and fees', 'icon' => 'file'],
            ],
        ]);
    }
}
