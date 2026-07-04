<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

// Seed the DB-driven navigation (M14). Idempotent: matches by label+route.
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // [label, route, icon, module, permission, feature, [children...]]
        $groups = [
            [null, null, null, null, null, null, [
                ['Dashboard', 'dashboard', 'LayoutDashboard', 'M1', null, null],
            ]],
            ['Author', null, null, null, null, null, [
                ['Reports', 'reports', 'FileText', 'M6', 'reports.view', null],
                ['Templates', 'templates', 'LayoutTemplate', 'M6', 'reports.create', null],
            ]],
            ['Data & Intelligence', null, null, null, null, null, [
                ['Data Sources', 'datasources', 'Database', 'M2', 'datasources.view', null],
                ['Knowledge Base', 'knowledge-base', 'BookOpen', 'M3', 'kb.view', null],
                ['AI Orchestration', 'ai-orchestration', 'Bot', 'M4', 'reports.create', 'multi_agent'],
            ]],
            ['Delivery', null, null, null, null, null, [
                ['Compile & Publish', 'compile', 'Package', 'M7', 'reports.publish', 'cra_compile'],
                ['API Delivery', 'api-delivery', 'Webhook', 'M11', 'apikeys.manage', null],
                ['Scheduler', 'scheduler', 'Clock', 'M12', 'reports.run', null],
            ]],
            ['Governance', null, null, null, null, null, [
                ['Projects', 'projects', 'FolderKanban', 'M14', 'projects.view', null],
                ['Users & Roles', 'users', 'Users', 'M14', 'users.manage', 'rbac_full'],
                ['Menu', 'menus', 'Menu', 'M14', 'menus.manage', null],
                ['Audit Log', 'audit', 'ScrollText', 'M1', 'audit.read', null],
                ['Settings', 'settings', 'Settings', 'M14', 'settings.manage', null],
                ['Constants', 'constants', 'Hash', 'M14', 'settings.manage', null],
            ]],
        ];

        $gSort = 0;
        foreach ($groups as [$label, $route, $icon, $module, $perm, $feature, $children]) {
            $parent = null;
            if ($label !== null) {
                $parent = Menu::updateOrCreate(
                    ['label' => $label, 'parent_id' => null],
                    ['route' => null, 'icon' => null, 'module' => null, 'permission' => null, 'feature' => null, 'sort' => $gSort, 'is_active' => true],
                );
            }
            $gSort++;

            $cSort = 0;
            foreach ($children as [$clabel, $croute, $cicon, $cmodule, $cperm, $cfeature]) {
                Menu::updateOrCreate(
                    ['label' => $clabel, 'route' => $croute],
                    [
                        'parent_id' => $parent?->id, 'icon' => $cicon, 'module' => $cmodule,
                        'permission' => $cperm, 'feature' => $cfeature, 'sort' => $cSort, 'is_active' => true,
                    ],
                );
                $cSort++;
            }
        }
    }
}
