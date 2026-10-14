<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StatusHistory extends Model
{
    public const UPDATED_AT = null; // history rows only have created_at

    protected $fillable = ['trackable_type', 'trackable_id', 'from_status', 'to_status', 'changed_by', 'remarks'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** The enrollment / document request / payment / clearance item this row belongs to. */
    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
