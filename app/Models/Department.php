<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    public const TYPE_ACADEMIC = 'academic';
    public const TYPE_OFFICE   = 'office';

    protected $fillable = ['code', 'name', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /** Staff accounts that belong to this department/office. */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
