<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// M1 (FR-M1.1) — authentication: login, token issue, status gating, self-register.
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_and_receives_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
    }

    public function test_invalid_credentials_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123'), 'status' => User::STATUS_ACTIVE]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_pending_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret123'), 'status' => User::STATUS_PENDING]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertStatus(422);
    }

    public function test_self_registration_creates_pending_account(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('data.status', User::STATUS_PENDING);

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'status' => User::STATUS_PENDING]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_me_returns_current_user(): void
    {
        $user = $this->actingWithPermissions(['reports.view']);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }
}
