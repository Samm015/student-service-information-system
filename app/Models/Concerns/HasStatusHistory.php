<?php

namespace App\Models\Concerns;

use App\Models\StatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Add to any model that has a `status` column and should keep a history of
 * its status changes (Enrollment, DocumentRequest, Payment, ClearanceItem).
 *
 * Always change status with $model->changeStatus(...) instead of setting
 * ->status directly, so the history stays complete.
 */
trait HasStatusHistory
{
    public function statusHistories(): MorphMany
    {
        return $this->morphMany(StatusHistory::class, 'trackable')->orderBy('id');
    }

    public function changeStatus(string $newStatus, ?User $by = null, ?string $remarks = null): static
    {
        $old = $this->status;

        $this->status = $newStatus;
        $this->save();

        $this->statusHistories()->create([
            'from_status' => $old,
            'to_status'   => $newStatus,
            'changed_by'  => $by?->id,
            'remarks'     => $remarks,
        ]);

        return $this;
    }
}
