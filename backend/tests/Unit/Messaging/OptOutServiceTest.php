<?php
namespace Tests\Unit\Messaging;

use App\Models\MessageOptOut;
use App\Models\Tenant;
use App\Services\Messaging\OptOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptOutServiceTest extends TestCase
{
    use RefreshDatabase;

    private OptOutService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OptOutService();
    }

    public function test_add_and_detect(): void
    {
        $tenant = Tenant::factory()->create();
        $this->svc->add($tenant->id, 'sms', '+5521999998888', 'user_request');

        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'sms', '+5521999998888'));
        $this->assertFalse($this->svc->isOptedOut($tenant->id, 'sms', '+5521988887777'));
    }

    public function test_channel_all_blocks_all(): void
    {
        $tenant = Tenant::factory()->create();
        $this->svc->add($tenant->id, 'all', 'joao@example.com', 'lgpd');

        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'email', 'joao@example.com'));
        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'sms', 'joao@example.com'));
        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'voice', 'joao@example.com'));
    }

    public function test_normalization_prevents_bypass_by_case(): void
    {
        $tenant = Tenant::factory()->create();
        $this->svc->add($tenant->id, 'email', 'JOAO@Example.com', 'user_request');

        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'email', 'joao@example.com'));
        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'email', '  joao@EXAMPLE.COM  '));
    }

    public function test_idempotent_add(): void
    {
        $tenant = Tenant::factory()->create();
        $this->svc->add($tenant->id, 'sms', '+5521999998888', 'user_request');
        $this->svc->add($tenant->id, 'sms', '+5521999998888', 'user_request');

        $count = MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'sms')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_remove(): void
    {
        $tenant = Tenant::factory()->create();
        $this->svc->add($tenant->id, 'sms', '+5521999998888', 'user_request');
        $this->assertTrue($this->svc->isOptedOut($tenant->id, 'sms', '+5521999998888'));

        $this->svc->remove($tenant->id, 'sms', '+5521999998888');
        $this->assertFalse($this->svc->isOptedOut($tenant->id, 'sms', '+5521999998888'));
    }

    public function test_cross_tenant_isolation(): void
    {
        $t1 = Tenant::factory()->create();
        $t2 = Tenant::factory()->create();
        $this->svc->add($t1->id, 'sms', '+5521999998888', 'user_request');

        $this->assertTrue($this->svc->isOptedOut($t1->id, 'sms', '+5521999998888'));
        $this->assertFalse($this->svc->isOptedOut($t2->id, 'sms', '+5521999998888'));
    }
}
