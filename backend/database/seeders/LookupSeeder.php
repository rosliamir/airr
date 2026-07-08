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
            // 4-tier RBAC (2026-07-05): super_admin (all projects) > system_admin
            // (own-created only) > user_level_1 "Manager band" > user_level_2 "Executive band".
            ['user_type', 'super_admin', 'Super Admin', 0, true],
            ['user_type', 'system_admin', 'System Admin', 1, true],
            ['user_type', 'user_level_1', 'User Level 1', 2, true],
            ['user_type', 'user_level_2', 'User Level 2', 3, true],
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
