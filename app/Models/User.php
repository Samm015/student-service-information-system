<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_STUDENT      = 'student';
    public const ROLE_REGISTRATION = 'registration';
    public const ROLE_CASHIER      = 'cashier';
    public const ROLE_DEPARTMENT   = 'department';
    public const ROLE_ADMIN        = 'admin';

    public const ROLES = [
        self::ROLE_STUDENT,
        self::ROLE_REGISTRATION,
        self::ROLE_CASHIER,
        self::ROLE_DEPARTMENT,
        self::ROLE_ADMIN,
    ];

    // Note: the old `student_no` column on users is no longer used.
    // The student number now lives on the `students` table.
    protected $fillable = ['name', 'email', 'password', 'role', 'department_id', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** Student profile (only for users whose role is "student"). */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /** The department/office a staff member works for. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // --- Role helpers --------------------------------------------------

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
