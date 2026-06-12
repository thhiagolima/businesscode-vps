<?php

namespace Tests\Unit\Services;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Messaging\EmailDomainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailDomainSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
    }

    private function infobipListPayload(array $domains): array
    {
        return [
            'results' => array_map(function (array $d) {
                return [
                    'domainId'   => $d['id'] ?? 1,
                    'domainName' => $d['name'],
                    'active'     => $d['active'] ?? false,
                    'dnsRecords' => [
                        [
                            'type' => 'TXT',
                            'name' => "selector1._domainkey.{$d['name']}",
                            'expectedValue' => 'v=DKIM1; k=rsa; p=MIIBI...',
                            'verified' => $d['dkim'] ?? false,
                        ],
                        [
                            'type' => 'TXT',
                            'name' => $d['name'],
                            'expectedValue' => 'v=spf1 include:spf.infobip.com ~all',
                            'verified' => $d['spf'] ?? false,
                        ],
                        [
                            'type' => 'CNAME',
                            'name' => "bounces.{$d['name']}",
                            'expectedValue' => 'bounces.infobip.com',
                            'verified' => $d['cname'] ?? false,
                        ],
                    ],
                ];
            }, $domains),
            'paging' => ['page' => 0, 'size' => count($domains), 'totalPages' => 1, 'totalResults' => count($domains)],
        ];
    }

    public function test_sync_creates_new_domain_with_null_tenant(): void
    {
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'pixreals.com', 'id' => 42, 'active' => true, 'dkim' => true, 'spf' => true, 'cname' => true],
                ]),
                200
            ),
        ]);

        $result = app(EmailDomainService::class)->syncFromInfobip();

        $this->assertSame(1, $result['synced']);
        $this->assertDatabaseHas('email_sender_domains', [
            'domain'    => 'pixreals.com',
            'tenant_id' => null,
            'status'    => 'active',
        ]);
    }

    public function test_sync_preserves_existing_tenant_assignment(): void
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'domain'    => 'pixreals.com',
            'status'    => 'pending',
        ]);

        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'pixreals.com', 'active' => true, 'dkim' => true, 'spf' => true, 'cname' => true],
                ]),
                200
            ),
        ]);

        app(EmailDomainService::class)->syncFromInfobip();

        $row = EmailSenderDomain::withoutGlobalScopes()->where('domain', 'pixreals.com')->first();
        $this->assertSame($tenant->id, $row->tenant_id, 'sync must NOT overwrite existing tenant_id');
        $this->assertSame('active', $row->status, 'sync should refresh status from Infobip');
    }

    public function test_sync_marks_status_verifying_when_partial(): void
    {
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'partial.com', 'dkim' => true, 'spf' => false, 'cname' => false],
                ]),
                200
            ),
        ]);

        app(EmailDomainService::class)->syncFromInfobip();

        $this->assertDatabaseHas('email_sender_domains', [
            'domain' => 'partial.com',
            'status' => 'verifying',
        ]);
    }

    public function test_sync_handles_paginated_response(): void
    {
        $page0 = [
            'results' => [
                ['domainId' => 1, 'domainName' => 'a.com', 'active' => false, 'dnsRecords' => []],
            ],
            'paging' => ['page' => 0, 'size' => 1, 'totalPages' => 2, 'totalResults' => 2],
        ];
        $page1 = [
            'results' => [
                ['domainId' => 2, 'domainName' => 'b.com', 'active' => false, 'dnsRecords' => []],
            ],
            'paging' => ['page' => 1, 'size' => 1, 'totalPages' => 2, 'totalResults' => 2],
        ];

        Http::fakeSequence('api.infobip.com/email/1/domains*')
            ->push($page0, 200)
            ->push($page1, 200);

        $result = app(EmailDomainService::class)->syncFromInfobip();

        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseHas('email_sender_domains', ['domain' => 'a.com']);
        $this->assertDatabaseHas('email_sender_domains', ['domain' => 'b.com']);

        Http::assertSent(fn($req) => str_contains($req->url(), 'page=0'));
        Http::assertSent(fn($req) => str_contains($req->url(), 'page=1'));
    }

    public function test_sync_requests_page_size_within_infobip_limit(): void
    {
        // Regression: Infobip /email/1/domains rejects size>20 with 400 Bad Request.
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'limited.com', 'active' => true, 'dkim' => true, 'spf' => true, 'cname' => true],
                ]),
                200
            ),
        ]);

        app(EmailDomainService::class)->syncFromInfobip();

        Http::assertSent(function ($req) {
            preg_match('/size=(\d+)/', $req->url(), $m);
            return isset($m[1]) && (int) $m[1] <= 20;
        });
    }

    public function test_sync_throws_runtime_exception_on_http_failure(): void
    {
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        app(EmailDomainService::class)->syncFromInfobip();
    }

    public function test_sync_throws_when_infobip_not_configured(): void
    {
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', '', 'encrypted');

        $this->expectException(\App\Exceptions\InfobipNotConfiguredException::class);
        app(EmailDomainService::class)->syncFromInfobip();
    }
}
