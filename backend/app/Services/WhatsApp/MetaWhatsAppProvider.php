<?php

namespace App\Services\WhatsApp;

use App\Services\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppProvider implements WhatsAppProviderInterface
{
    private const BASE_URL = 'https://graph.facebook.com';
    private const API_VERSION = 'v20.0';

    public function __construct(private SettingsService $settings) {}

    private function makeClient(int $tenantId): PendingRequest
    {
        $token = $this->settings->get($tenantId, 'whatsapp', 'access_token', '');
        if (empty($token)) {
            throw new \RuntimeException('WhatsApp access_token não configurado para este tenant.');
        }

        return Http::withToken($token)
            ->baseUrl(self::BASE_URL . '/' . self::API_VERSION)
            ->acceptJson()
            ->timeout(15);
    }

    private function phoneNumberId(int $tenantId): string
    {
        $id = $this->settings->get($tenantId, 'whatsapp', 'phone_number_id', '');
        if (empty($id)) {
            throw new \RuntimeException('WhatsApp phone_number_id não configurado.');
        }
        return $id;
    }

    public function testConnection(int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);
            $resp = $client->get("/{$phoneId}");

            if ($resp->successful()) {
                return ['ok' => true, 'phone_display' => $resp->json('display_phone_number', ''), 'error' => null];
            }
            return ['ok' => false, 'phone_display' => null, 'error' => $resp->json('error.message', $resp->body())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'phone_display' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components, int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to, 'type' => 'template',
                'template' => ['name' => $templateName, 'language' => ['code' => $languageCode]],
            ];
            if (!empty($components)) $payload['template']['components'] = $components;

            $resp = $client->post("/{$phoneId}/messages", $payload);

            if ($resp->successful()) {
                $mid = $resp->json('messages.0.id');
                Log::channel('whatsapp')->info('meta.template.sent', ['to' => $to, 'wamid' => $mid]);
                return ['ok' => true, 'message_id' => $mid, 'error' => null];
            }
            $error = $resp->json('error.message', $resp->body());
            Log::channel('whatsapp')->warning('meta.template.failed', ['to' => $to, 'error' => $error]);
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('meta.template.exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendText(string $to, string $text, int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $resp = $client->post("/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $text],
            ]);

            if ($resp->successful()) {
                $mid = $resp->json('messages.0.id');
                Log::channel('whatsapp')->info('meta.text.sent', ['to' => $to, 'wamid' => $mid]);
                return ['ok' => true, 'message_id' => $mid, 'error' => null];
            }
            $error = $resp->json('error.message', $resp->body());
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendMedia(string $to, string $mediaType, string $url, ?string $caption, int $tenantId): array
    {
        try {
            $client = $this->makeClient($tenantId);
            $phoneId = $this->phoneNumberId($tenantId);

            $media = ['link' => $url];
            if ($caption && in_array($mediaType, ['image', 'video', 'document'])) $media['caption'] = $caption;

            $resp = $client->post("/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp', 'to' => $to, 'type' => $mediaType, $mediaType => $media,
            ]);

            if ($resp->successful()) {
                return ['ok' => true, 'message_id' => $resp->json('messages.0.id'), 'error' => null];
            }
            return ['ok' => false, 'message_id' => null, 'error' => $resp->json('error.message', $resp->body())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function getTemplates(int $tenantId): array
    {
        return Cache::remember("whatsapp_templates_meta_{$tenantId}", 300, function () use ($tenantId) {
            try {
                $client = $this->makeClient($tenantId);
                $wabaId = $this->settings->get($tenantId, 'whatsapp', 'waba_id', '');
                if (empty($wabaId)) return [];

                $resp = $client->get("/{$wabaId}/message_templates", ['status' => 'APPROVED', 'limit' => 100]);
                return $resp->successful() ? $resp->json('data', []) : [];
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('meta.templates.exception', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }
}
