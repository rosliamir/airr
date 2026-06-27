<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// M14 — a reference value in a category (user_type, project_type, …).
class Lookup extends Model
{
    protected $fillable = ['category', 'value', 'label', 'sort', 'is_active', 'is_system'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }
}
