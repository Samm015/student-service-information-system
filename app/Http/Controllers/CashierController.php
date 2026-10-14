<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Support\StaffPortal;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Cashier portal home: stats, walk-in payment form, pending list and payment history. */
class CashierController extends Controller
{
    public function __invoke(Request $request, StaffPortal $staff)
    {
        return Inertia::render('Cashier', [
            'stats'     => $staff->cashierStats(),
            'payments'  => $staff->paymentList($request),
            'fee_types' => collect(StorePaymentRequest::WALK_IN_FEE_TYPES)
                ->map(fn ($t) => ['value' => $t, 'label' => StaffPortal::feeLabel($t)])->values(),
            'today'     => today()->toDateString(),
            'queue'     => $staff->queuePanel($request->user()),
            'clearance' => $request->user()->department_id
                ? $staff->clearanceList($request, $request->user()->department_id)
                : null,
        ]);
    }
}
