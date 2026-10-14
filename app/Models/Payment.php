<?php

namespace App\Models;

use App\Models\Concerns\HasStatusHistory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasStatusHistory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_VOID    = 'void';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_VOID];

    public const FEE_TYPES = ['tuition', 'miscellaneous', 'document_fee', 'clearance_fee'];

    /** In-memory default so a new model already has its starting status. */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'student_id', 'document_request_id', 'fee_type', 'amount', 'status',
        'or_number', 'paid_at', 'recorded_by', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    /** The cashier who recorded the payment. */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Manual void rules: not already void, and not tied to a document request that is
     * still alive (cancel/reject the request instead; that voids its charge automatically).
     */
    public function canBeVoided(): bool
    {
        if ($this->status === self::STATUS_VOID) {
            return false;
        }

        if (! $this->document_request_id) {
            return true;
        }

        return in_array($this->documentRequest?->status, [DocumentRequest::STATUS_REJECTED, DocumentRequest::STATUS_CANCELLED], true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Mark as paid, issue the official receipt number (OR-0101) and log it. */
    public function markPaid(User $cashier, ?CarbonInterface $paidAt = null): static
    {
        $this->forceFill([
            'or_number'   => sprintf('OR-%04d', Payment::whereNotNull('or_number')->count() + 1),
            'paid_at'     => $paidAt ?? now(),
            'recorded_by' => $cashier->id,
        ]);

        return $this->changeStatus(self::STATUS_PAID, $cashier);
    }
}
