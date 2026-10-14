<?php

namespace App\Models;

use App\Models\Concerns\HasStatusHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClearanceItem extends Model
{
    use HasStatusHistory;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_SIGNED   = 'signed';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SIGNED, self::STATUS_REJECTED];

    /** In-memory default so a new model already has its starting status. */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'clearance_id', 'clearance_requirement_id', 'department_id',
        'status', 'signed_by', 'signed_at', 'remarks',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function clearance(): BelongsTo
    {
        return $this->belongsTo(Clearance::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ClearanceRequirement::class, 'clearance_requirement_id');
    }

    /** The office responsible for signing this item. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
