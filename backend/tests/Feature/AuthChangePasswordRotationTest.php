<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P0R-04 — Após trocar a senha, todos os tokens Sanctum devem ser revogados.
 * Sem isso, um atacante que vazou um token via XSS continua autenticado
 * mesmo após o titular trocar a senha (sessão imortal).
 */
class AuthChangePasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_password_revokes_all_existing_tokens(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u@x.com', 'password' => Hash::make('OldPass123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();

        // 2 tokens vivos antes da troca (cenário: cliente logou em 2 dispositivos).
        $t1 = $user->createToken('mobile')->plainTextToken;
        $t2 = $user->createToken('desktop')->plainTextToken;
        $this->assertSame(2, $user->tokens()->count());

        $resp = $this->withHeader('Authorization', "Bearer {$t1}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'OldPass123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ]);
        $resp->assertOk();

        // Ambos os tokens (incluindo o usado na troca) devem estar revogados.
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_change_password_writes_audit_log(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => Hash::make('OldPass123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'OldPass123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])->assertOk();

        $row = \App\Models\AuditLog::withoutGlobalScopes()
            ->where('action', 'auth.password_changed')
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row->metadata['tokens_revoked'] ?? false));
    }
}
