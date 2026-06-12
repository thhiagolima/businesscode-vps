<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Migration utility: marca tokens pre-existentes com a sentinel
 * __server-to-server para que sobrevivam ao teto global de 24h do Sanctum.
 * Dry-run por padrão; --apply persiste; suporta escopo por tenant ou por id.
 */
class MarkTokensServerToServerCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = '__server-to-server';

    public function test_dry_run_does_not_persist_changes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('legacy', ['campaigns.send'])->accessToken;

        $this->artisan('tokens:mark-server-to-server', ['--token' => $token->id])
            ->assertExitCode(0);

        $fresh = PersonalAccessToken::find($token->id);
        $this->assertNotContains(self::SENTINEL, $fresh->abilities ?? []);
    }

    public function test_apply_persists_sentinel_on_selected_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('legacy', ['campaigns.send'])->accessToken;

        $this->artisan('tokens:mark-server-to-server', [
            '--token' => $token->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $fresh = PersonalAccessToken::find($token->id);
        $this->assertContains(self::SENTINEL, $fresh->abilities);
        $this->assertContains('campaigns.send', $fresh->abilities);
    }

    public function test_tenant_scope_isolates_other_tenants(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = User::factory()->create(['tenant_id' => $a->id]);
        $ub = User::factory()->create(['tenant_id' => $b->id]);
        $ta = $ua->createToken('a')->accessToken;
        $tb = $ub->createToken('b')->accessToken;

        $this->artisan('tokens:mark-server-to-server', [
            '--tenant' => $a->id,
            '--apply'  => true,
        ])->assertExitCode(0);

        $this->assertContains(self::SENTINEL, PersonalAccessToken::find($ta->id)->abilities);
        $this->assertNotContains(self::SENTINEL, PersonalAccessToken::find($tb->id)->abilities ?? []);
    }

    public function test_idempotent_does_not_duplicate_sentinel(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('legacy', ['*', self::SENTINEL])->accessToken;

        $this->artisan('tokens:mark-server-to-server', [
            '--token' => $token->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $abilities = PersonalAccessToken::find($token->id)->abilities;
        $count = count(array_filter($abilities, fn ($a) => $a === self::SENTINEL));
        $this->assertSame(1, $count, 'sentinel must appear at most once');
    }
}
