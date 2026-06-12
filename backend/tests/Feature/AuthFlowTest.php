<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_tenant_records_lgpd_consent_and_returns_token(): void
    {
        Plan::factory()->create(['slug' => 'free', 'included_balance_cents' => 750]);
        config(['business.terms_version' => '2026.1.0']);

        $response = $this->withServerVariables(['HTTP_USER_AGENT' => 'AuditTest/1.0'])
            ->postJson('/api/v1/auth/register', [
                'name'                  => 'Test User',
                'email'                 => 'test@example.com',
                'password'              => 'X9pK$mQ2vLfR8nW',
                'password_confirmation' => 'X9pK$mQ2vLfR8nW',
                'accept_terms'          => 1,
                'accept_privacy'        => 1,
            ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertNotEmpty($data['token']);
        $this->assertNotEmpty($data['user']['tenant']['id']);

        // Registro público nunca pode escalar para superadmin — frontend usa esse role
        // pra decidir labels e gates de UI, então o backend é a fonte da verdade.
        $this->assertSame('admin', $data['user']['role']);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertSame('admin', $user->role);
        $this->assertNotNull($user->lgpd_consented_at);
        $this->assertSame('2026.1.0', $user->lgpd_consent_version);
        $this->assertNotEmpty($user->lgpd_consent_ip);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action'  => 'auth.register',
            'user_agent' => 'AuditTest/1.0',
        ]);
    }

    public function test_register_rejects_without_consent(): void
    {
        Plan::factory()->create(['slug' => 'free', 'included_balance_cents' => 750]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'No Consent',
            'email'                 => 'noconsent@example.com',
            'password'              => 'X9pK$mQ2vLfR8nW',
            'password_confirmation' => 'X9pK$mQ2vLfR8nW',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['accept_terms', 'accept_privacy']);
    }

    public function test_login_returns_token_and_audit_records_ua(): void
    {
        Plan::factory()->create(['slug' => 'free', 'included_balance_cents' => 750]);

        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Test',
            'email'                 => 'login@example.com',
            'password'              => 'X9pK$mQ2vLfR8nW',
            'password_confirmation' => 'X9pK$mQ2vLfR8nW',
            'accept_terms'          => 1,
            'accept_privacy'        => 1,
        ]);

        $response = $this->withServerVariables(['HTTP_USER_AGENT' => 'LoginAgent/2.0'])
            ->postJson('/api/v1/auth/login', [
                'email'    => 'login@example.com',
                'password' => 'X9pK$mQ2vLfR8nW',
            ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('email', 'login@example.com')->first();
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action'  => 'auth.login',
            'user_agent' => 'LoginAgent/2.0',
        ]);
    }

    public function test_login_with_wrong_password_returns_401_and_audits_failure(): void
    {
        Plan::factory()->create(['slug' => 'free', 'included_balance_cents' => 750]);

        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Test',
            'email'                 => 'fail@example.com',
            'password'              => 'X9pK$mQ2vLfR8nW',
            'password_confirmation' => 'X9pK$mQ2vLfR8nW',
            'accept_terms'          => 1,
            'accept_privacy'        => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'fail@example.com',
            'password' => 'WrongPass9',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_register_validates_password_strength(): void
    {
        Plan::factory()->create(['slug' => 'free', 'included_balance_cents' => 750]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Weak',
            'email'                 => 'weak@example.com',
            'password'              => '12345678',
            'password_confirmation' => '12345678',
            'accept_terms'          => 1,
            'accept_privacy'        => 1,
        ]);

        $response->assertStatus(422);
    }
}
