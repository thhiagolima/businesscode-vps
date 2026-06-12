<?php

namespace Tests\Feature\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Infobip\InfobipService;
use App\Services\Messaging\MessagingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransactionalEmailRegistersDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
        Mail::fake();
        Queue::fake();
    }

    public function test_transactional_source_uses_laravel_mail(): void
    {
        $plan   = Plan::factory()->create(['quiet_hours_enabled' => false]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 500]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $dispatch = app(MessagingService::class)->dispatch(
            $tenant,
            $user,
            'email',
            [
                'to'      => 'recipient@example.com',
                'subject' => 'Transacional',
                'content' => 'Body conteúdo',
                'source'  => 'transactional',
            ],
            null,
            'reject'
        );

        $this->assertSame('laravel_mail', $dispatch->provider);
        $this->assertSame('queued', $dispatch->status);

        // Run the job — uses TransactionalMailer which calls Mail::raw
        (new SendMessageJob($dispatch->id))->handle(app(InfobipService::class));

        $dispatch->refresh();
        $this->assertSame('sent', $dispatch->status);

        // TransactionalMailer uses Mail::raw — captured by Mail::fake but not via
        // assertSentCount (which only counts class-based mailables). Verify via
        // the external_message_id prefix the mailer sets on success.
        $this->assertSame('mail-' . $dispatch->id, $dispatch->external_message_id);
    }
}
