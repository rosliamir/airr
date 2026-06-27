<?php

namespace Tests\Feature;

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// M14 — DB-driven navigation: permission-filtered nav + CRUD gating.
class MenuTest extends TestCase
{
    use RefreshDatabase;

    private function seedMenu(): void
    {
        $author = Menu::create(['label' => 'Author', 'sort' => 0]);
        Menu::create(['label' => 'Reports', 'route' => 'reports', 'parent_id' => $author->id, 'permission' => 'reports.view', 'sort' => 0]);
        Menu::create(['label' => 'Templates', 'route' => 'templates', 'parent_id' => $author->id, 'permission' => 'reports.create', 'sort' => 1]);
    }

    public function test_nav_hides_items_without_permission(): void
    {
        $this->seedMenu();
        $this->actingWithPermissions(['reports.view']); // not reports.create

        $res = $this->getJson('/api/menus/nav')->assertOk();
        $author = collect($res->json('data'))->firstWhere('label', 'Author');

        $labels = collect($author['children'])->pluck('label');
        $this->assertTrue($labels->contains('Reports'));
        $this->assertFalse($labels->contains('Templates')); // gated out
    }

    public function test_empty_group_is_hidden(): void
    {
        Menu::create(['label' => 'Secret', 'sort' => 0]); // group with no visible children
        Menu::create(['label' => 'Hidden', 'route' => 'x', 'permission' => 'roles.manage', 'parent_id' => 1, 'sort' => 0]);
        $this->actingWithPermissions([]); // no permissions

        $res = $this->getJson('/api/menus/nav')->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_crud_requires_menus_manage(): void
    {
        $this->actingWithPermissions([]); // no menus.manage
        $this->getJson('/api/menus')->assertForbidden();
        $this->postJson('/api/menus', ['label' => 'X'])->assertForbidden();
    }

    public function test_admin_can_create_menu(): void
    {
        $this->actingWithPermissions(['menus.manage']);
        $this->postJson('/api/menus', ['label' => 'New', 'route' => 'new', 'permission' => 'reports.view'])
            ->assertCreated()->assertJsonPath('data.label', 'New');

        $this->assertDatabaseHas('menus', ['label' => 'New', 'route' => 'new']);
    }
}
