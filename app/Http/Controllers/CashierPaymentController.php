<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettlePaymentRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\VoidPaymentRequest;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\Payment;
use App\Models\Student;
use App\Support\StaffPortal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money handling. A payment only counts once the backend has recorded it:
 * status = paid, an OR number, the date, and the cashier who recorded it.
 */
class CashierPaymentController extends Controller
{
    /** Walk-in payment (tuition, miscellaneous, clearance fee): recorded and receipted immediately. */
    public function store(StorePaymentRequest $request)
    {
        $user    = $request->user();
        $data    = $request->validated();
        $student = Student::findByNumber($data['student_no']);

        $payment = DB::transaction(function () use ($user, $data, $student) {
            $duplicate = Payment::where('student_id', $student->id)
                ->where('fee_type', $data['fee_type'])
                ->where('status', Payment::STATUS_PENDING)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'student_no' => 'This student already has a pending ' . StaffPortal::feeLabel($data['fee_type']) . ' charge. Record it from the Pending payments list.',
                ]);
            }
            
            $recent = Payment::where('student_id', $student->id)->where('fee_type', $data['fee_type'])
                ->where('amount', $data['amount'])->where('status', Payment::STATUS_PAID)
                ->where('created_at', '>=', now()->subMinutes(2))->exists();
            if ($recent) {
                throw ValidationException::withMessages(['amount' => 'An identical payment was just recorded for this student.']);
            }

            $payment = Payment::create([
                'student_id' => $student->id,
                'fee_type'   => $data['fee_type'],
                'amount'     => $data['amount'],
                'remarks'    => $data['remarks'] ?? null,
            ]);
            $payment->statusHistories()->create(['from_status' => null, 'to_status' => Payment::STATUS_PENDING, 'changed_by' => $user->id]);

            return $payment->markPaid($user, $this->paidAt($data['paid_on'] ?? null));
        });

        AuditLog::record('payment.recorded', $payment, null, $payment->only('amount', 'fee_type', 'or_number'));

        return back()->with('success', "Payment of ₱" . StaffPortal::money($payment->amount) . " recorded. Official receipt {$payment->or_number}.");
    }

    /** Settle an existing pending payment (for example a document fee). */
    public function pay(SettlePaymentRequest $request, Payment $payment)
    {
        $user = $request->user();
        $data = $request->validated();

        $result = DB::transaction(function () use ($payment, $user, $data) {
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isPending()) {
                return ['error' => "This payment is already {$fresh->status}. Nothing was changed."];
            }

            // The full amount due must be received (no partial payments in this system).
            if (round((float) $data['amount'], 2) !== round((float) $fresh->amount, 2)) {
                throw ValidationException::withMessages([
                    'amount' => 'The amount received must be exactly ₱' . StaffPortal::money($fresh->amount) . '.',
                ]);
            }

            if (! empty($data['remarks'])) {
                $fresh->remarks = $data['remarks'];
            }
            $fresh->markPaid($user, $this->paidAt($data['paid_on'] ?? null));

            // A paid document fee releases the request to Registration for processing.
            if ($fresh->document_request_id) {
                $doc = DocumentRequest::whereKey($fresh->document_request_id)->lockForUpdate()->first();

                if ($doc && $doc->status === DocumentRequest::STATUS_AWAITING_PAYMENT) {
                    $doc->changeStatus(DocumentRequest::STATUS_PROCESSING, $user, "Payment received ({$fresh->or_number})");
                }
            }

            return ['payment' => $fresh];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        AuditLog::record('payment.recorded', $result['payment'], null, $result['payment']->only('amount', 'fee_type', 'or_number'));

        return back()->with('success', "Payment recorded. Official receipt {$result['payment']->or_number}.");
    }

    public function void(VoidPaymentRequest $request, Payment $payment)
    {
        $user    = $request->user();
        $remarks = trim($request->validated('remarks'));

        $result = DB::transaction(function () use ($payment, $user, $remarks) {
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === Payment::STATUS_VOID) {
                return ['error' => 'This payment is already void.'];
            }
            if (! $fresh->canBeVoided()) {
                return ['error' => 'This payment belongs to a document request that is still active. Cancel or reject the request first.'];
            }

            $fresh->remarks = $remarks;
            $fresh->changeStatus(Payment::STATUS_VOID, $user, $remarks);

            return ['payment' => $fresh];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        AuditLog::record('payment.voided', $result['payment'], null, ['remarks' => $remarks]);

        return back()->with('success', 'Payment voided.');
    }

    /** Today's date means "right now"; an earlier date keeps the current time of day. */
    private function paidAt(?string $date): Carbon
    {
        return $date ? Carbon::parse($date)->setTimeFrom(now()) : now();
    }
}
