<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null; // audit rows are never edited

    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * One-line helper for controllers:
     *   AuditLog::record('payment.recorded', $payment, null, $payment->toArray());
     */
    public static function record(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null): self
    {
        return static::create([
            'user_id'        => auth()->id(),
            'action'         => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id'   => $subject?->getKey(),
            'old_values'     => $old,
            'new_values'     => $new,
            'ip_address'     => request()?->ip(),
        ]);
    }
}
