<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class QueueEntry extends Model
{
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_SERVING   = 'serving';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_SERVING,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_CANCELLED,
    ];

    /** An entry is "active" while the student is still waiting or being served. */
    public const ACTIVE_STATUSES = [self::STATUS_WAITING, self::STATUS_SERVING];

    public const SERVICES = ['enrollment', 'clearance', 'grades', 'documents', 'payment'];

    /** What the student sees in the service dropdown. */
    public const SERVICE_LABELS = [
        'enrollment' => 'Enrollment',
        'clearance'  => 'Clearance',
        'grades'     => 'Grade Viewing',
        'documents'  => 'Document Request',
        'payment'    => 'Payment',
    ];

    /** Which windows serve which services. Registration: everything except payment. Cashier: payment. */
    public const REGISTRATION_SERVICES = ['enrollment', 'clearance', 'grades', 'documents'];
    public const CASHIER_SERVICES      = ['payment'];

    /** Services this staff user may call / serve (admins: all). */
    public static function servicesFor(User $user): array
    {
        return match (true) {
            $user->isAdmin()                               => self::SERVICES,
            $user->hasRole(User::ROLE_REGISTRATION)        => self::REGISTRATION_SERVICES,
            $user->hasRole(User::ROLE_CASHIER)             => self::CASHIER_SERVICES,
            default                                        => [],
        };
    }

    /** Called to a window but processing has not started yet. */
    public function isCalled(): bool
    {
        return $this->status === self::STATUS_SERVING && $this->started_at === null;
    }

    /** Shown before the number, e.g. A-024. (Becomes an admin setting later.) */
    public const PREFIX = 'A';

    /** In-memory default so a new model already has its starting status. */
    protected $attributes = ['status' => 'waiting'];

    protected $fillable = [
        'student_id', 'service', 'queue_date', 'queue_number', 'queue_code', 'status',
        'counter', 'served_by', 'started_at', 'completed_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'queue_date'   => 'date:Y-m-d',
            'queue_number' => 'integer',
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'is_active'    => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Keep `is_active` in sync with `status` automatically. The database's
        // unique index on (student_id, service, is_active) then makes it
        // impossible to have two active entries for the same student + service.
        static::saving(function (QueueEntry $entry) {
            $entry->is_active = in_array($entry->status, self::ACTIVE_STATUSES, true) ? true : null;
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    /** Does this student already have a waiting/serving entry for this service? */
    public static function hasActive(int $studentId, string $service): bool
    {
        return static::where('student_id', $studentId)
            ->where('service', $service)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();
    }

    public static function serviceLabel(string $service): string
    {
        return self::SERVICE_LABELS[$service] ?? ucfirst($service);
    }

    /**
     * Queue numbers restart every day. An entry from an earlier day that was
     * never served is closed as "skipped" so it cannot block the student
     * from taking a new number (the one-active-entry rule would otherwise
     * keep rejecting them forever).
     */
    public static function expireStale(?int $studentId = null): void
    {
        static::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDate('queue_date', '<', today())
            ->when($studentId, fn ($q) => $q->where('student_id', $studentId))
            ->get()
            ->each(function (QueueEntry $entry) {
                $entry->status = self::STATUS_SKIPPED; // saving() hook clears is_active
                $entry->save();
            });
    }

    /** Take the next queue number for today. */
    public static function issue(Student $student, string $service): self
    {
        return DB::transaction(function () use ($student, $service) {
            $today  = today();
            $number = (int) static::whereDate('queue_date', $today)->max('queue_number') + 1;

            return static::create([
                'student_id'   => $student->id,
                'service'      => $service,
                'queue_date'   => $today,
                'queue_number' => $number,
                'queue_code'   => sprintf('%s-%03d', self::PREFIX, $number),
            ]);
        });
    }
}
