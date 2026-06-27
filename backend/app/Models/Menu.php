<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// M14 — a navigation menu item (DB-driven). Hierarchy via parent_id.
class Menu extends Model
{
    protected $fillable = [
        'parent_id', 'label', 'route', 'icon', 'module', 'permission', 'feature', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort');
    }
}
