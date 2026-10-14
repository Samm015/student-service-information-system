<?php

namespace App\Models;

use App\Models\Concerns\HasStatusHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRequest extends Model
{
    use HasStatusHistory;

    public const STATUS_PENDING          = 'pending';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PROCESSING       = 'processing';
    public const STATUS_READY            = 'ready';
    public const STATUS_RELEASED         = 'released';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_CANCELLED        = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_RELEASED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Still being worked on: blocks a second request for the same document. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_AWAITING_PAYMENT,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
    ];

    /** A student may still cancel the request in these statuses. */
    public const CANCELLABLE_STATUSES = [self::STATUS_PENDING, self::STATUS_AWAITING_PAYMENT];

    public const MAX_COPIES = 10;

    /** In-memory default so a new model already has its starting status. */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'reference_no', 'student_id', 'document_type_id', 'copies', 'purpose', 'expected_release_date',
        'status', 'fee_total', 'processed_by', 'processed_at', 'released_by', 'released_at', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'copies'       => 'integer',
            'expected_release_date' => 'date:Y-m-d',
            'fee_total'    => 'decimal:2',
            'processed_at' => 'datetime',
            'released_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Reference number like DR-0045, built from the row id so it can never collide.
        static::created(function (DocumentRequest $request) {
            if (! $request->reference_no) {
                $request->reference_no = sprintf('DR-%04d', $request->id);
                $request->saveQuietly();
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function requiresPayment(): bool
    {
        return (float) $this->fee_total > 0;
    }

    public function isPaid(): bool
    {
        return $this->payments()->where('status', Payment::STATUS_PAID)->exists();
    }

    /** Cancel every unpaid charge of this request (used when it is cancelled or rejected). */
    public function voidPendingPayments(?User $by, string $reason): void
    {
        $this->payments()->where('status', Payment::STATUS_PENDING)->get()
            ->each(fn (Payment $p) => $p->changeStatus(Payment::STATUS_VOID, $by, $reason));
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
