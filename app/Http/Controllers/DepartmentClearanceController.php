<?php

namespace App\Http\Controllers;

use App\Http\Requests\SignClearanceItemRequest;
use App\Models\AuditLog;
use App\Models\ClearanceItem;
use Illuminate\Support\Facades\DB;

/** An office signs (or rejects) ONE clearance item. SignClearanceItemRequest checks office ownership. */
class DepartmentClearanceController extends Controller
{
    public function update(SignClearanceItemRequest $request, ClearanceItem $clearanceItem)
    {
        $user    = $request->user();
        $status  = $request->validated('status');
        $remarks = trim((string) $request->validated('remarks')) ?: null;

        $result = DB::transaction(function () use ($clearanceItem, $user, $status, $remarks) {
            $item = ClearanceItem::whereKey($clearanceItem->id)->lockForUpdate()->firstOrFail();

            // Duplicate signing: a signed item is final, so signed_by / signed_at are never overwritten.
            if ($item->status === ClearanceItem::STATUS_SIGNED) {
                $who = $item->signer?->name ?? 'an officer';

                return ['error' => "This requirement was already signed by {$who} on {$item->signed_at?->format('M d, Y')}."];
            }

            if ($status === ClearanceItem::STATUS_REJECTED && $item->status === ClearanceItem::STATUS_REJECTED) {
                return ['error' => 'This requirement is already marked as rejected.'];
            }

            if ($status === ClearanceItem::STATUS_SIGNED) {
                $item->forceFill(['signed_by' => $user->id, 'signed_at' => now(), 'remarks' => $remarks]);
            } else {
                $item->forceFill(['signed_by' => null, 'signed_at' => null, 'remarks' => $remarks]);
            }
            $item->changeStatus($status, $user, $remarks);
            $item->clearance->refreshStatus();

            return ['item' => $item];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        AuditLog::record("clearance_item.{$status}", $result['item'], null, ['remarks' => $remarks]);

        return back()->with('success', $status === ClearanceItem::STATUS_SIGNED ? 'Requirement signed.' : 'Requirement rejected. The student can see your remarks.');
    }
}
