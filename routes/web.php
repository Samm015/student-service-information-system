<?php

use App\Http\Controllers\AdminCatalogController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CashierPaymentController;
use App\Http\Controllers\DepartmentClearanceController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DepartmentGradeController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RegistrationEnrollmentController;
use App\Http\Controllers\StaffDocumentController;
use App\Http\Controllers\StaffQueueController;
use App\Http\Controllers\StudentClearanceController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentEnrollmentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\StudentQueueController;
use App\Support\AdminCatalog;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
| Structure: public -> guest -> authenticated -> one group PER ROLE.
| The `role:` middleware (App\Http\Middleware\EnsureUserHasRole) is the real
| backend lock; hiding links in the React sidebar is only cosmetic.
| Admins pass every `role:` check.
|
| Session 3 filled the Registration, Cashier and Department groups. Admin (users, departments,
| subjects, document types) is filled in too.
*/

Route::get('/', fn () => auth()->check() ? redirect('/' . auth()->user()->role) : redirect('/login'));

// Tiny endpoint the page calls after Back/Forward to ask "am I still logged in?"
// (see the script in resources/views/app.blade.php). Never cached.
Route::get('/session-check', fn () => response()
    ->json(['auth' => auth()->check()])
    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0'));

// ---- Guests only -----------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::inertia('/login', 'Login')->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

// ---- Any logged-in user ------------------------------------------------------
Route::middleware(['auth', 'active', 'nocache'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // The sidebar shows "Document Request" to every role, so the page stays open
    // to all logged-in users. Who may do WHAT with a request is enforced by
    // DocumentRequestPolicy once the endpoints exist.
    // Students list/submit/view/cancel their own requests (ownership is checked in the
    // controller through DocumentRequestPolicy). Registration/Cashier get the staff view of
    // the same URLs; only Registration can change a status (PATCH route in its group below).
    Route::get('/documents', [DocumentRequestController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentRequestController::class, 'store'])->name('documents.store');
    Route::get('/documents/{documentRequest}', [DocumentRequestController::class, 'show'])
        ->whereNumber('documentRequest')->name('documents.show');
    Route::post('/documents/{documentRequest}/cancel', [DocumentRequestController::class, 'cancel'])
        ->whereNumber('documentRequest')->name('documents.cancel');

    // ---- Student portal ------------------------------------------------------
    Route::middleware('role:student')->group(function () {
        Route::get('/student', StudentDashboardController::class)->name('student.dashboard');
        Route::get('/student/history', StudentHistoryController::class)->name('student.history');

        Route::post('/student/enrollments', [StudentEnrollmentController::class, 'store'])->name('student.enrollments.store');
        Route::get('/student/enrollments/{enrollment}', [StudentEnrollmentController::class, 'show'])
            ->whereNumber('enrollment')->name('student.enrollments.show');

        Route::post('/student/clearance', [StudentClearanceController::class, 'store'])->name('student.clearance.store');

        Route::post('/student/queue', [StudentQueueController::class, 'store'])->name('student.queue.store');
        Route::post('/student/queue/{queueEntry}/cancel', [StudentQueueController::class, 'cancel'])
            ->whereNumber('queueEntry')->name('student.queue.cancel');
    });

    // ---- Registration portal -------------------------------------------------
    Route::middleware('role:registration')->group(function () {
        Route::get('/registration', RegistrationController::class)->name('registration.dashboard');

        Route::get('/registration/enrollments/{enrollment}', [RegistrationEnrollmentController::class, 'show'])
            ->whereNumber('enrollment')->name('registration.enrollments.show');
        Route::patch('/registration/enrollments/{enrollment}', [RegistrationEnrollmentController::class, 'update'])
            ->whereNumber('enrollment')->name('registration.enrollments.update');

        // Document processing (the list and detail pages are GET /documents and /documents/{id} above).
        Route::patch('/documents/{documentRequest}/status', [StaffDocumentController::class, 'update'])
            ->whereNumber('documentRequest')->name('documents.status');
        // Registration alone decides when a document will be released.
        Route::patch('/documents/{documentRequest}/release-date', [StaffDocumentController::class, 'releaseDate'])
            ->whereNumber('documentRequest')->name('documents.release-date');
    });

    // ---- Service windows: Registration (all services but payment) and Cashier (payment).
    // Which services each role may serve is checked again in QueueEntry::servicesFor().
    Route::middleware('role:registration,cashier')->prefix('staff/queue')->group(function () {
        Route::post('/call-next', [StaffQueueController::class, 'call'])->name('staff.queue.call');
        Route::patch('/{queueEntry}', [StaffQueueController::class, 'update'])
            ->whereNumber('queueEntry')->name('staff.queue.update');
    });

    // ---- Cashier portal ------------------------------------------------------
    Route::middleware('role:cashier')->group(function () {
        Route::get('/cashier', CashierController::class)->name('cashier.dashboard');
        Route::post('/cashier/payments', [CashierPaymentController::class, 'store'])->name('cashier.payments.store');
        Route::post('/cashier/payments/{payment}/pay', [CashierPaymentController::class, 'pay'])
            ->whereNumber('payment')->name('cashier.payments.pay');
        Route::post('/cashier/payments/{payment}/void', [CashierPaymentController::class, 'void'])
            ->whereNumber('payment')->name('cashier.payments.void');
    });

    // ---- Department portal ---------------------------------------------------
    Route::middleware('role:department')->group(function () {
        Route::get('/department', DepartmentController::class)->name('department.dashboard');
        Route::post('/department/grades', [DepartmentGradeController::class, 'store'])->name('department.grades.store');
    });
      // Any office signs ITS OWN items (ClearanceItemPolicy::sign checks the office).
    Route::middleware('role:department,registration,cashier')->group(function () {
        Route::patch('/department/clearance-items/{clearanceItem}', [DepartmentClearanceController::class, 'update'])
          ->whereNumber('clearanceItem')->name('department.clearance.update');
    });

    // ---- Admin portal (admin only: no roles listed) --------------------------
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/', AdminController::class)->name('admin.dashboard');

        Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])
            ->whereNumber('user')->name('admin.users.update');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'status'])
            ->whereNumber('user')->name('admin.users.status');

        // Departments, subjects and document types share one controller (see App\Support\AdminCatalog).
        Route::get('/{resource}', [AdminCatalogController::class, 'index'])
            ->whereIn('resource', AdminCatalog::RESOURCES)->name('admin.catalog.index');
        Route::post('/{resource}', [AdminCatalogController::class, 'store'])
            ->whereIn('resource', AdminCatalog::RESOURCES)->name('admin.catalog.store');
        Route::patch('/{resource}/{id}', [AdminCatalogController::class, 'update'])
            ->whereIn('resource', AdminCatalog::RESOURCES)->whereNumber('id')->name('admin.catalog.update');
    });
});
