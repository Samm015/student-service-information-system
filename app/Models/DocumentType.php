<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    protected $fillable = ['code', 'name', 'fee', 'processing_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'fee'             => 'decimal:2',
            'processing_days' => 'integer',
            'is_active'       => 'boolean',
        ];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }
}
