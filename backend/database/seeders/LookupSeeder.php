<?php

namespace Database\Seeders;

use App\Models\Lookup;
use Illuminate\Database\Seeder;

// Seed reference lookups (M14). Idempotent by [category, value].
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // user_type — structural types (system flag = referenced by code).
            ['user_type', 'system_admin', 'System Administrator', 0, true],
            ['user_type', 'admin', 'Administrator', 1, true],
            ['user_type', 'user', 'User', 2, true],
            // project_type — editable.
            ['project_type', 'development', 'Development', 0, false],
            ['project_type', 'production', 'Production', 1, false],
            ['project_type', 'internal', 'Internal', 2, false],
            ['project_type', 'client', 'Client', 3, false],
        ];

        foreach ($rows as [$category, $value, $label, $sort, $system]) {
            Lookup::updateOrCreate(
                ['category' => $category, 'value' => $value],
                ['label' => $label, 'sort' => $sort, 'is_active' => true, 'is_system' => $system],
            );
        }
    }
}
