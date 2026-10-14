<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grade extends Model
{
    public const REMARKS = ['passed', 'failed', 'incomplete', 'dropped'];

    protected $fillable = [
        'student_id', 'subject_id', 'school_year', 'semester',
        'grade', 'remarks', 'encoded_by', 'encoded_at',
    ];

    protected function casts(): array
    {
        return [
            'grade'      => 'decimal:2',
            'encoded_at' => 'datetime',
        ];
    }

    /** Passed subjects: marked "passed", or a grade of 3.00 or better. */
    public function scopePassed(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('remarks', 'passed')
              ->orWhere(fn (Builder $g) => $g->whereNotNull('grade')->where('grade', '<=', 3.00));
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encoded_by');
    }
}
