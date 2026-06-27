<?php

namespace Tests\Feature;

use App\Models\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// M14 — lookups (reference values for dropdowns) + CRUD gating.
class LookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_values_readable_by_any_user(): void
    {
        Lookup::create(['category' => 'project_type', 'value' => 'dev', 'label' => 'Development', 'is_active' => true]);
        Lookup::create(['category' => 'project_type', 'value' => 'old', 'label' => 'Old', 'is_active' => false]);
        $this->actingWithPermissions([]); // any authenticated user

        $res = $this->getJson('/api/lookups?category=project_type')->assertOk();
        $vals = collect($res->json('data'))->pluck('value');
        $this->assertTrue($vals->contains('dev'));
        $this->assertFalse($vals->contains('old')); // inactive hidden
    }

    public function test_crud_requires_settings_manage(): void
    {
        $this->actingWithPermissions([]);
        $this->getJson('/api/lookups/manage')->assertForbidden();
        $this->postJson('/api/lookups', ['category' => 'x', 'value' => 'y', 'label' => 'Y'])->assertForbidden();
    }

    public function test_admin_can_crud_lookup(): void
    {
        $this->actingWithPermissions(['settings.manage']);

        $id = $this->postJson('/api/lookups', ['category' => 'project_type', 'value' => 'client', 'label' => 'Client'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/lookups/{$id}", ['category' => 'project_type', 'value' => 'client', 'label' => 'Client Co'])
            ->assertOk()->assertJsonPath('data.label', 'Client Co');
        $this->deleteJson("/api/lookups/{$id}")->assertNoContent();
    }

    public function test_system_lookup_cannot_be_deleted(): void
    {
        $this->actingWithPermissions(['settings.manage']);
        $sys = Lookup::create(['category' => 'user_type', 'value' => 'admin', 'label' => 'Administrator', 'is_system' => true]);

        $this->deleteJson("/api/lookups/{$sys->id}")->assertStatus(422)->assertJsonPath('error.code', 'SYSTEM_LOOKUP');
    }
}
