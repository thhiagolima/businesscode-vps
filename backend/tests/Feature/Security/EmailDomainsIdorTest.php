<?php

namespace Tests\Feature\Security;

use App\Models\EmailSenderDomain;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailDomainsIdorTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndDomain(): array
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        $domain = new EmailSenderDomain();
        $domain->forceFill([
            'tenant_id' => $tenant->id, 'domain' => 'x'.uniqid().'.example.com', 'status' => 'pending',
        ])->save();
        return [$tenant, $user, $domain];
    }

    public function test_show_other_tenant_domain_returns_404(): void
    {
        [, $userA, ] = $this->makeUserAndDomain();
        [, , $domainB] = $this->makeUserAndDomain();
        Sanctum::actingAs($userA);

        $this->getJson("/api/v1/email-domains/{$domainB->id}")->assertStatus(404);
    }

    public function test_verify_other_tenant_domain_returns_404(): void
    {
        [, $userA, ] = $this->makeUserAndDomain();
        [, , $domainB] = $this->makeUserAndDomain();
        Sanctum::actingAs($userA);

        $this->postJson("/api/v1/email-domains/{$domainB->id}/verify")->assertStatus(404);
    }

    public function test_destroy_other_tenant_domain_returns_404(): void
    {
        [, $userA, ] = $this->makeUserAndDomain();
        [, , $domainB] = $this->makeUserAndDomain();
        Sanctum::actingAs($userA);

        $this->deleteJson("/api/v1/email-domains/{$domainB->id}")->assertStatus(404);
        // Domain B must still exist.
        $this->assertNotNull(EmailSenderDomain::withoutGlobalScopes()->withTrashed()->find($domainB->id));
        // And must NOT have been soft-deleted.
        $this->assertNull(EmailSenderDomain::withoutGlobalScopes()->find($domainB->id)->deleted_at);
    }
}
