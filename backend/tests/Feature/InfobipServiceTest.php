<?php

namespace Tests\Feature;

use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InfobipServiceTest extends TestCase
{
    use RefreshDatabase;
    private InfobipService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed settings for test
        $settings = app(SettingsService::class);
        $settings->upsertGlobal('infobip', 'api_key', 'test-api-key-123', 'encrypted');
        $settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $settings->upsertGlobal('infobip', 'default_sender', 'TestSMS', 'string');

        $this->service = app(InfobipService::class);
    }

    public function test_test_connection_success(): void
    {
        Http::fake([
            'api.infobip.com/sms/1/reports*' => Http::response(['results' => []], 200),
        ]);

        $result = $this->service->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertEquals(200, $result['status']);
    }

    public function test_test_connection_unauthorized(): void
    {
        Http::fake([
            'api.infobip.com/sms/1/reports*' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'Invalid login details']],
            ], 401),
        ]);

        $result = $this->service->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('401', $result['error']);
    }

    public function test_send_sms_success(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response([
                'messages' => [['messageId' => 'msg-123', 'status' => ['groupName' => 'PENDING']]],
            ], 200),
        ]);

        $result = $this->service->sendSms('+5521999999999', 'Teste SMS', 'TestSMS');

        $this->assertTrue($result['ok']);
        $this->assertEquals('msg-123', $result['message_id']);
        $this->assertNull($result['error']);

        // Assert v3 schema is being sent: sender (not from), content.text (not flat text)
        Http::assertSent(function ($request) {
            $body = $request->data();
            $msg  = $body['messages'][0] ?? [];
            return str_contains($request->url(), '/sms/3/messages')
                && ($msg['sender'] ?? null) === 'TestSMS'
                && ($msg['content']['text'] ?? null) === 'Teste SMS'
                && ($msg['destinations'][0]['to'] ?? null) === '+5521999999999'
                && !array_key_exists('from', $msg)
                && !array_key_exists('text', $msg);
        });
    }

    public function test_send_sms_failure(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'Bad request']],
            ], 400),
        ]);

        $result = $this->service->sendSms('+5521999999999', 'Teste', 'TestSMS');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('Bad request', $result['error']);
    }

    public function test_send_email_success(): void
    {
        Http::fake([
            'api.infobip.com/email/3/send' => Http::response([
                'messages' => [['messageId' => 'email-456']],
            ], 200),
        ]);

        $result = $this->service->sendEmail('test@example.com', 'Subject', '<p>Body</p>', 'from@test.com', 'Test');

        $this->assertTrue($result['ok']);
        $this->assertEquals('email-456', $result['message_id']);
    }

    public function test_send_email_sends_html_part_with_text_fallback(): void
    {
        // Recipients must see formatted HTML, not raw <p> tags. The provider
        // multipart body must include a `html` part with the HTML, plus a `text`
        // part with the tag-stripped fallback for plain-text-only clients.
        Http::fake([
            'api.infobip.com/email/3/send' => Http::response([
                'messages' => [['messageId' => 'email-html-1']],
            ], 200),
        ]);

        $html = '<h1>Olá</h1><p>Pedido <strong>#ABC</strong> confirmado.</p>';
        $this->service->sendEmail('dest@example.com', 'Hi', $html, 'from@example.com', '', null);

        Http::assertSent(function ($request) use ($html) {
            $body = (string) $request->body();
            return str_contains($body, 'name="html"')
                && str_contains($body, $html)
                && str_contains($body, 'name="text"')
                && str_contains($body, 'Pedido #ABC confirmado.');
        });
    }

    public function test_send_voice_tts_success(): void
    {
        // Infobip wraps the messageId inside a "messages" array (same envelope
        // as /sms/3/messages and /email/3/send), NOT at the top level.
        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response([
                'bulkId'   => 'bulk-1',
                'messages' => [['to' => '+5521999999999', 'messageId' => 'voice-789']],
            ], 200),
        ]);

        $result = $this->service->sendVoice('+5521999999999', 'Hello test', 'TestVoice');

        $this->assertTrue($result['ok']);
        $this->assertEquals('voice-789', $result['message_id']);
    }

    public function test_send_voice_audio_url_success(): void
    {
        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response([
                'messages' => [['messageId' => 'voice-audio-101']],
            ], 200),
        ]);

        $result = $this->service->sendVoice('+5521999999999', '', 'TestVoice', 'https://example.com/audio.mp3');

        $this->assertTrue($result['ok']);
        $this->assertEquals('voice-audio-101', $result['message_id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/tts/3/single')
                && $request['audioFileUrl'] === 'https://example.com/audio.mp3';
        });
    }

    public function test_send_email_includes_reply_to_when_set(): void
    {
        Http::fake([
            'api.infobip.com/email/3/send' => Http::response([
                'messages' => [['messageId' => 'eml-1']],
            ], 200),
        ]);

        $this->service->sendEmail(
            'dest@example.com',
            'Hi',
            'Body',
            'from@example.com',
            'Sender Name',
            'reply@example.com'
        );

        Http::assertSent(function ($request) {
            $body = (string) $request->body();
            return str_contains($body, 'name="replyTo"')
                && str_contains($body, 'reply@example.com');
        });
    }

    public function test_send_email_omits_reply_to_when_null(): void
    {
        Http::fake([
            'api.infobip.com/email/3/send' => Http::response([
                'messages' => [['messageId' => 'eml-2']],
            ], 200),
        ]);

        $this->service->sendEmail('dest@example.com', 'Hi', 'Body', 'from@example.com', '', null);

        Http::assertSent(function ($request) {
            return ! str_contains((string) $request->body(), 'name="replyTo"');
        });
    }

    public function test_send_voice_audio_url_omits_language_and_voice(): void
    {
        // Regression: Infobip returns REJECTED_ONLY_NEURAL_VOICES_AVAILABLE_FOR_LANGUAGE
        // when language is sent without a neural voice name in audio-file mode.
        // Audio file playback is voice-agnostic, so payload must NOT include
        // language or voice when audioFileUrl is set.
        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response(['messageId' => 'mid'], 200),
        ]);

        $this->service->sendVoice('+5521999999999', '', 'TestVoice', 'https://example.com/audio.mp3');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ! array_key_exists('language', $body)
                && ! array_key_exists('voice', $body)
                && ! array_key_exists('text', $body)
                && ($body['audioFileUrl'] ?? null) === 'https://example.com/audio.mp3';
        });
    }

    public function test_send_voice_tts_includes_voice_name_for_neural_language(): void
    {
        // Settings ship with voice_name=Camila (neural BR PT) by default;
        // ensure the payload carries voice.name + voice.gender for TTS mode.
        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response(['messageId' => 'mid'], 200),
        ]);

        $this->service->sendVoice('+5521999999999', 'Olá', 'TestVoice');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return ($body['text'] ?? null) === 'Olá'
                && ($body['language'] ?? null) === 'pt'
                && ($body['voice']['name'] ?? null) === 'Camila'
                && ($body['voice']['gender'] ?? null) === 'female';
        });
    }
}
