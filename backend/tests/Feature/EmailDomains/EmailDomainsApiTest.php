<?php

namespace Tests\Feature\EmailDomains;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailDomainsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
    }

    private function actAs(array $abilities = ['*'], int $balanceCents = 10000): User
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => false,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balanceCents,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    private function infobipRegisterPayload(string $domain): array
    {
        return [
            'domainId'   => 12345,
            'domainName' => $domain,
            'dnsRecords' => [
                [
                    'type'          => 'TXT',
                    'name'          => "selector1._domainkey.{$domain}",
                    'expectedValue' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIB...',
                    'verified'      => false,
                ],
                [
                    'type'          => 'TXT',
                    'name'          => $domain,
                    'expectedValue' => 'v=spf1 include:spf.infobip.com ~all',
                    'verified'      => false,
                ],
                [
                    'type'          => 'CNAME',
                    'name'          => "bounces.{$domain}",
                    'expectedValue' => 'bounces.infobip.com',
                    'verified'      => false,
                ],
            ],
            'active' => false,
        ];
    }

    private function infobipVerifyPayload(string $domain, bool $dkim, bool $spf, bool $cname): array
    {
        return [
            'domainId'   => 12345,
            'domainName' => $domain,
            'dnsRecords' => [
                [
                    'type'          => 'TXT',
                    'name'          => "selector1._domainkey.{$domain}",
                    'expectedValue' => 'v=DKIM1; k=rsa; p=MII...',
                    'verified'      => $dkim,
                ],
                [
                    'type'          => 'TXT',
                    'name'          => $domain,
                    'expectedValue' => 'v=spf1 include:spf.infobip.com ~all',
                    'verified'      => $spf,
                ],
                [
                    'type'          => 'CNAME',
                    'name'          => "bounces.{$domain}",
                    'expectedValue' => 'bounces.infobip.com',
                    'verified'      => $cname,
                ],
            ],
            'active' => $dkim && $spf && $cname,
        ];
    }

    public function test_create_returns_dns_records(): void
    {
        $this->actAs();
        $domain = 'marketing.example.com';

        Http::fake([
            'api.infobip.com/email/1/domains' => Http::response($this->infobipRegisterPayload($domain), 200),
            "api.infobip.com/email/1/domains/{$domain}/tracking" => Http::response([], 200),
        ]);

        $resp = $this->postJson('/api/v1/email-domains', [
            'domain' => $domain,
            'tracking_opens' => false,
            'tracking_clicks' => false,
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.domain', $domain)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.dkim_selector', 'selector1');

        // Confirm the create call sent targetedDailyTraffic (the field whose absence
        // produced BAD_REQUEST in production on 2026-05-28).
        Http::assertSent(function ($request) use ($domain) {
            if ($request->url() !== 'https://api.infobip.com/email/1/domains' || $request->method() !== 'POST') {
                return false;
            }
            $data = $request->data();
            return ($data['domainName'] ?? null) === $domain
                && is_int($data['targetedDailyTraffic'] ?? null);
        });

        $body = $resp->json('data');
        $this->assertNotEmpty($body['dkim_value']);
        $this->assertStringContainsString('v=spf1', $body['spf_value']);
        $this->assertStringContainsString('bounces.infobip.com', $body['return_path_value']);
    }

    public function test_create_returns_502_on_infobip_validation_error(): void
    {
        // Regression test for production 500: Infobip 400 (RequestException via retry+throw)
        // must surface as a friendly 502 instead of crashing the controller.
        $this->actAs();
        $domain = 'invalid.example.com';

        Http::fake([
            'api.infobip.com/email/1/domains' => Http::response([
                'requestError' => [
                    'serviceException' => [
                        'messageId' => 'BAD_REQUEST',
                        'text'      => 'Bad Request',
                        'validationErrors' => ['targetedDailyTraffic' => ['must be a positive integer']],
                    ],
                ],
            ], 400),
        ]);

        $resp = $this->postJson('/api/v1/email-domains', [
            'domain' => $domain,
            'tracking_opens'  => false,
            'tracking_clicks' => false,
        ]);

        $resp->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Falha ao registrar dominio no Infobip: Bad Request']);

        // No DB row should have been created.
        $this->assertDatabaseMissing('email_sender_domains', ['domain' => $domain]);
    }

    public function test_verify_marks_active_when_all_records_verified(): void
    {
        $user = $this->actAs();
        $domain = 'verified.example.com';

        $model = EmailSenderDomain::create([
            'tenant_id'   => $user->tenant_id,
            'domain'      => $domain,
            'status'      => 'pending',
            'dkim_value'  => 'preset',
            'spf_value'   => 'preset',
            'return_path_value' => 'preset',
        ]);

        Http::fake([
            "api.infobip.com/email/1/domains/{$domain}" => Http::response(
                $this->infobipVerifyPayload($domain, true, true, true), 200
            ),
        ]);

        $resp = $this->postJson("/api/v1/email-domains/{$model->id}/verify");

        $resp->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.dkim_verified', true)
            ->assertJsonPath('data.spf_verified', true)
            ->assertJsonPath('data.return_path_verified', true);
    }

    public function test_verify_keeps_pending_when_not_all_verified(): void
    {
        $user = $this->actAs();
        $domain = 'partial.example.com';

        $model = EmailSenderDomain::create([
            'tenant_id'   => $user->tenant_id,
            'domain'      => $domain,
            'status'      => 'pending',
        ]);

        Http::fake([
            "api.infobip.com/email/1/domains/{$domain}" => Http::response(
                $this->infobipVerifyPayload($domain, true, false, false), 200
            ),
        ]);

        $resp = $this->postJson("/api/v1/email-domains/{$model->id}/verify");

        $resp->assertStatus(200)
            ->assertJsonPath('data.status', 'verifying')
            ->assertJsonPath('data.dkim_verified', true)
            ->assertJsonPath('data.spf_verified', false)
            ->assertJsonPath('data.return_path_verified', false);
    }

    public function test_cross_tenant_isolation(): void
    {
        // Tenant A creates a domain
        $userA = $this->actAs();
        $domain = 'tenant-a.example.com';
        EmailSenderDomain::create([
            'tenant_id' => $userA->tenant_id,
            'domain'    => $domain,
            'status'    => 'active',
        ]);

        // Capture the actual model id for tenant A
        $modelId = EmailSenderDomain::withoutGlobalScopes()
            ->where('tenant_id', $userA->tenant_id)
            ->value('id');

        // Switch to tenant B
        $userB = $this->actAs();

        // index for B does NOT include A's domain
        $resp = $this->getJson('/api/v1/email-domains');
        $resp->assertOk();
        $this->assertCount(0, $resp->json('data'));

        // show A's id from B's session → 404
        $this->getJson("/api/v1/email-domains/{$modelId}")->assertNotFound();

        // verify A's id from B → 404 (resource not found in B's scope)
        $this->postJson("/api/v1/email-domains/{$modelId}/verify")->assertNotFound();

        // delete A's id from B → 404
        $this->deleteJson("/api/v1/email-domains/{$modelId}")->assertNotFound();
    }

    public function test_send_email_rejects_unverified_from_domain(): void
    {
        $this->actAs(['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'      => 'dest@example.com',
            'subject' => 'Hi',
            'content' => 'Body',
            'from'    => 'sender@meu-dominio.com',
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $errors = $resp->json('errors.from');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('nao esta autenticado', $errors[0]);
    }

    public function test_send_email_allows_from_when_domain_active(): void
    {
        $user = $this->actAs(['messaging:email']);
        $domain = 'meu-dominio-ativo.com';

        EmailSenderDomain::create([
            'tenant_id' => $user->tenant_id,
            'domain'    => $domain,
            'status'    => 'active',
            'dkim_verified'        => true,
            'spf_verified'         => true,
            'return_path_verified' => true,
        ]);

        // For email send: fake all outbound HTTP to Infobip (multipart endpoint)
        Http::fake([
            'api.infobip.com/email/3/send' => Http::response(['messages' => [['messageId' => 'msg-1']]], 200),
        ]);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'      => 'dest@example.com',
            'subject' => 'Hello',
            'content' => 'Body',
            'from'    => "alguem@{$domain}",
        ]);

        $resp->assertStatus(202);
    }
}
