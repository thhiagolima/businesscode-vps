<?php

namespace Tests\Feature\Messaging;

use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verifies template-variable substitution on the direct messaging API
 * endpoints (SMS, Voice, Email). Variables come from the optional
 * `variables` payload field and replace {key} placeholders in the
 * outbound content (and subject, for email) before dispatch.
 */
class MessagingVariablesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);

        Http::fake([
            'api.infobip.com/*' => Http::response(['messages' => [['messageId' => 'mid-1']]], 200),
        ]);

        Queue::fake();
    }

    private function actAs(array $abilities, int $balanceCents = 100000): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => $balanceCents]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    private function lastDispatch(int $tenantId): MessageDispatch
    {
        return MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();
    }

    // ---------------------------------------------------------------- SMS ----

    public function test_sms_substitutes_variables_in_content(): void
    {
        $user = $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'        => '+5521980194445',
            'content'   => 'Olá {primeiro_nome}, faltam {dias} dias.',
            'variables' => ['primeiro_nome' => 'Pedro', 'dias' => 7],
        ]);

        $resp->assertStatus(202);
        $d = $this->lastDispatch($user->tenant_id);
        $this->assertSame('Olá Pedro, faltam 7 dias.', $d->content);
        // Variables map persisted for audit (coerced to strings on the request).
        $this->assertSame(['primeiro_nome' => 'Pedro', 'dias' => '7'], $d->variables);
    }

    public function test_dispatch_variables_column_is_null_when_omitted(): void
    {
        $user = $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521980194445',
            'content' => 'sem variáveis',
        ]);

        $resp->assertStatus(202);
        $this->assertNull($this->lastDispatch($user->tenant_id)->variables);
    }

    public function test_sms_leaves_unknown_placeholders_intact(): void
    {
        $user = $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'        => '+5521980194445',
            'content'   => 'Hi {nome} {missing}',
            'variables' => ['nome' => 'Ana'],
        ]);

        $resp->assertStatus(202);
        $this->assertSame('Hi Ana {missing}', $this->lastDispatch($user->tenant_id)->content);
    }

    public function test_sms_rejects_invalid_variable_key(): void
    {
        $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'        => '+5521980194445',
            'content'   => 'Body',
            'variables' => ['has space' => 'x'],
        ]);

        $resp->assertStatus(422);
    }

    public function test_sms_rejects_oversized_variable_value(): void
    {
        $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'        => '+5521980194445',
            'content'   => 'Body {nome}',
            'variables' => ['nome' => str_repeat('a', 501)],
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['variables.nome']);
    }

    // ---------------------------------------------------------------- Email ----

    public function test_email_substitutes_variables_in_subject_and_content(): void
    {
        $user = $this->actAs(['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'        => 'cliente@exemplo.com',
            'subject'   => 'Oi {primeiro_nome}',
            'content'   => '<p>Olá {primeiro_nome}, pedido <strong>#{pedido}</strong> confirmado.</p>',
            'variables' => ['primeiro_nome' => 'Pedro', 'pedido' => 'ABC123'],
        ]);

        $resp->assertStatus(202);
        $d = $this->lastDispatch($user->tenant_id);
        $this->assertSame('Oi Pedro', $d->subject);
        $this->assertStringContainsString('Olá Pedro', $d->content);
        $this->assertStringContainsString('#ABC123', $d->content);
        $this->assertSame(['primeiro_nome' => 'Pedro', 'pedido' => 'ABC123'], $d->variables);
    }

    // ---------------------------------------------------------------- Pentest ----

    public function test_email_sanitizes_xss_injected_through_variable_value(): void
    {
        // A variable value that smuggles <script> must still be stripped by
        // the existing HTML sanitizer running AFTER substitution.
        $user = $this->actAs(['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'        => 'cliente@exemplo.com',
            'subject'   => 'Hi',
            'content'   => 'Olá {nome}',
            'variables' => ['nome' => 'A<script>alert(1)</script>B'],
        ]);

        $resp->assertStatus(202);
        $content = $this->lastDispatch($user->tenant_id)->content;
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringNotContainsString('</script>', $content);
    }

    public function test_email_strips_crlf_injected_through_variable_in_subject(): void
    {
        // CWE-93 — a variable value carrying \r\n must not produce extra
        // SMTP headers in the subject.
        $user = $this->actAs(['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'        => 'cliente@exemplo.com',
            'subject'   => 'Oi {nome}',
            'content'   => 'Body',
            'variables' => ['nome' => "Pedro\r\nBcc: evil@x.com"],
        ]);

        $resp->assertStatus(202);
        $subject = $this->lastDispatch($user->tenant_id)->subject;
        // Real defense: no \r or \n in the rendered subject means an attacker
        // cannot inject a new SMTP header line. The literal "Bcc:" text
        // remaining inline in the subject is harmless (it's just text).
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
    }
}
