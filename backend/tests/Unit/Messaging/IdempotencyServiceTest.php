<?php
namespace Tests\Unit\Messaging;

use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\Messaging\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new IdempotencyService();
    }

    public function test_lookup_returns_null_when_no_key(): void
    {
        $tenant = Tenant::factory()->create();
        $this->assertNull($this->svc->lookup($tenant->id, null, ['to' => '+5521999998888']));
        $this->assertNull($this->svc->lookup($tenant->id, '', ['to' => '+5521999998888']));
    }

    public function test_lookup_hit_returns_existing_dispatch(): void
    {
        $tenant = Tenant::factory()->create();
        $payload = ['to' => '+5521999998888', 'content' => 'Hello'];
        $hash = $this->svc->hashPayload($payload);

        $dispatch = MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'                => $tenant->id,
            'channel'                  => 'sms',
            'source'                   => 'api',
            'to'                       => '+5521999998888',
            'content'                  => 'Hello',
            'provider'                 => 'infobip',
            'status'                   => 'queued',
            'idempotency_key'          => 'key-abc',
            'idempotency_payload_hash' => $hash,
        ]);

        $hit = $this->svc->lookup($tenant->id, 'key-abc', $payload);
        $this->assertNotNull($hit);
        $this->assertEquals($dispatch->id, $hit->id);
    }

    public function test_lookup_with_diverging_payload_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $payload = ['to' => '+5521999998888', 'content' => 'Hello'];

        MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'                => $tenant->id,
            'channel'                  => 'sms',
            'source'                   => 'api',
            'to'                       => '+5521999998888',
            'content'                  => 'Hello',
            'provider'                 => 'infobip',
            'status'                   => 'queued',
            'idempotency_key'          => 'key-abc',
            'idempotency_payload_hash' => $this->svc->hashPayload($payload),
        ]);

        $this->expectException(IdempotencyKeyReuseException::class);
        $this->svc->lookup($tenant->id, 'key-abc', ['to' => '+5521999998888', 'content' => 'CHANGED']);
    }

    public function test_expired_idempotency_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        $payload = ['to' => '+5521999998888', 'content' => 'Hello'];

        $dispatch = MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'                => $tenant->id,
            'channel'                  => 'sms',
            'source'                   => 'api',
            'to'                       => '+5521999998888',
            'content'                  => 'Hello',
            'provider'                 => 'infobip',
            'status'                   => 'queued',
            'idempotency_key'          => 'key-old',
            'idempotency_payload_hash' => $this->svc->hashPayload($payload),
        ]);
        // Force created_at 25h ago
        $dispatch->created_at = now()->subHours(25);
        $dispatch->saveQuietly();

        $this->assertNull($this->svc->lookup($tenant->id, 'key-old', $payload));
    }

    public function test_hash_is_stable_regardless_of_key_order(): void
    {
        $a = ['to' => '+5521999998888', 'content' => 'Hi', 'meta' => ['x' => 1, 'y' => 2]];
        $b = ['meta' => ['y' => 2, 'x' => 1], 'content' => 'Hi', 'to' => '+5521999998888'];
        $this->assertEquals($this->svc->hashPayload($a), $this->svc->hashPayload($b));
    }
}
