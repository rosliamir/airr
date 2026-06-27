<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Reviewer-toggled readiness for a feature key (see config/features.php).
class FeatureStatus extends Model
{
    protected $fillable = ['feature_key', 'human_tested', 'ready_for_prod', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['human_tested' => 'boolean', 'ready_for_prod' => 'boolean'];
    }
}
