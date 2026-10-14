<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clearance extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLEARED = 'cleared';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CLEARED];

    /** In-memory default so a new model already has its starting status. */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['student_id', 'school_year', 'semester', 'status', 'cleared_at'];

    protected function casts(): array
    {
        return ['cleared_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClearanceItem::class);
    }

    /**
     * Create a clearance for a student with one item per active requirement.
     * A NULL requirement department becomes the student's own department.
     */
    public static function openFor(Student $student, string $schoolYear, string $semester): self
    {
        $clearance = static::create([
            'student_id'  => $student->id,
            'school_year' => $schoolYear,
            'semester'    => $semester,
        ]);

        $requirements = ClearanceRequirement::where('is_active', true)->orderBy('sort_order')->get();

        foreach ($requirements as $requirement) {
            $clearance->items()->create([
                'clearance_requirement_id' => $requirement->id,
                'department_id'            => $requirement->department_id ?? $student->department_id,
            ]);
        }

        return $clearance;
    }

    /** Mark the whole clearance as cleared once every item is signed. */
    public function refreshStatus(): void
    {
        $allSigned = $this->items()->where('status', '!=', ClearanceItem::STATUS_SIGNED)->doesntExist();

        $this->update([
            'status'     => $allSigned ? self::STATUS_CLEARED : self::STATUS_PENDING,
            'cleared_at' => $allSigned ? now() : null,
        ]);
    }
}
