<?php

namespace Tests\Feature;

use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Without notifyUrl/webhooks.delivery.url in the send request, Infobip has
 * nowhere to POST the status, so every dispatch stays "sent" forever. These
 * guard that each channel registers the public callback URL (with the secret
 * token in the path) on every send.
 */
class InfobipNotifyUrlTest extends TestCase
{
    use RefreshDatabase;

    private InfobipService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $s = app(SettingsService::class);
        $s->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        $s->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $s->upsertGlobal('infobip', 'webhook_secret', 'tok-123', 'string');
        $s->upsertGlobal('infobip', 'webhook_base_url', 'https://dash.businesscode.com.br', 'string');
        $this->service = app(InfobipService::class);
    }

    public function test_sms_includes_delivery_webhook_url(): void
    {
        Http::fake(['api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'm']]], 200)]);

        $this->service->sendSms('+5521999999999', 'oi', 'Sender');

        Http::assertSent(function ($req) {
            $url = $req->data()['messages'][0]['webhooks']['delivery']['url'] ?? null;
            return $url === 'https://dash.businesscode.com.br/api/v1/webhooks/infobip/delivery/tok-123';
        });
    }

    public function test_voice_includes_notify_url(): void
    {
        Http::fake(['api.infobip.com/tts/3/single' => Http::response(['messages' => [['messageId' => 'm']]], 200)]);

        $this->service->sendVoice('+5521999999999', 'Olá', 'Voice');

        Http::assertSent(function ($req) {
            return ($req->data()['notifyUrl'] ?? null)
                === 'https://dash.businesscode.com.br/api/v1/webhooks/infobip/delivery/tok-123';
        });
    }

    public function test_email_includes_notify_url(): void
    {
        Http::fake(['api.infobip.com/email/3/send' => Http::response(['messages' => [['messageId' => 'm']]], 200)]);

        $this->service->sendEmail('to@example.com', 'Sub', '<p>b</p>', 'from@example.com', '', null);

        Http::assertSent(function ($req) {
            $body = (string) $req->body();
            return str_contains($body, 'name="notifyUrl"')
                && str_contains($body, 'https://dash.businesscode.com.br/api/v1/webhooks/infobip/email-events/tok-123');
        });
    }

    public function test_no_notify_url_when_secret_missing(): void
    {
        // No breakage when unconfigured: omit notifyUrl entirely.
        app(SettingsService::class)->upsertGlobal('infobip', 'webhook_secret', '', 'string');
        $service = app(InfobipService::class);

        Http::fake(['api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'm']]], 200)]);

        $service->sendSms('+5521999999999', 'oi', 'Sender');

        Http::assertSent(fn ($req) => ! isset($req->data()['messages'][0]['webhooks']));
    }
}
