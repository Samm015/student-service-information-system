<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['code', 'title', 'units', 'department_id', 'is_active'];

    protected function casts(): array
    {
        return ['units' => 'integer', 'is_active' => 'boolean'];
    }

    /** The department that owns the subject (and encodes its grades). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function enrollments(): BelongsToMany
    {
        return $this->belongsToMany(Enrollment::class, 'enrollment_subject')->withTimestamps();
    }
}
