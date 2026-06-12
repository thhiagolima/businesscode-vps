<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspendedUserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_user_cannot_login(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email'     => 'sus@test.com',
            'password'  => bcrypt('Secret123A'),
            'status'    => 'suspended',
        ]);

        $resp = $this->postJson('/api/v1/auth/login', [
            'email'    => 'sus@test.com',
            'password' => 'Secret123A',
        ]);

        $resp->assertStatus(403)
            ->assertJsonPath('message', 'Conta suspensa. Contate o suporte.');
    }

    public function test_active_user_login_returns_force_password_reset_flag(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->create([
            'tenant_id'            => $tenant->id,
            'email'                => 'fpr@test.com',
            'password'             => bcrypt('Secret123A'),
            'force_password_reset' => true,
        ]);

        $resp = $this->postJson('/api/v1/auth/login', [
            'email'    => 'fpr@test.com',
            'password' => 'Secret123A',
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.user.force_password_reset', true);
    }
}
