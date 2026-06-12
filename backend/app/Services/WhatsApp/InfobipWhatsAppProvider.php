<?php

namespace App\Services\WhatsApp;

use App\Models\InfobipWhatsAppNumber;
use App\Services\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InfobipWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(private SettingsService $settings) {}

    private function makeClient(): PendingRequest
    {
        $apiKey  = $this->settings->getGlobal('infobip', 'api_key', '');
        $baseUrl = $this->settings->getGlobal('infobip', 'base_url', 'api.infobip.com');

        if (empty($apiKey)) {
            throw new \RuntimeException('Infobip API key não configurada.');
        }

        return Http::withHeaders(['Authorization' => "App {$apiKey}"])
            ->baseUrl("https://{$baseUrl}")
            ->acceptJson()
            ->timeout(15);
    }

    private function senderNumber(int $tenantId): string
    {
        $number = InfobipWhatsAppNumber::where('tenant_id', $tenantId)->first();
        if (!$number) {
            throw new \RuntimeException('Nenhum número WhatsApp Infobip atribuído a este tenant.');
        }
        return $number->sender;
    }

    public function testConnection(int $tenantId): array
    {
        try {
            $client = $this->makeClient();
            $sender = $this->senderNumber($tenantId);

            // Test by fetching sender info
            $resp = $client->get('/whatsapp/2/senders');

            if ($resp->successful()) {
                $body = $resp->json();
                $senders = collect($body['results'] ?? (is_array($body) && !isset($body['results']) ? $body : []));
                $found = $senders->firstWhere('sender', $sender) ?? $senders->firstWhere('number', $sender);
                if ($found) {
                    return ['ok' => true, 'phone_display' => $found['number'] ?? $sender, 'error' => null];
                }
                return ['ok' => false, 'phone_display' => null, 'error' => "Sender {$sender} não encontrado na Infobip."];
            }
            return ['ok' => false, 'phone_display' => null, 'error' => 'HTTP ' . $resp->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'phone_display' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendText(string $to, string $text, int $tenantId): array
    {
        try {
            $client = $this->makeClient();
            $sender = $this->senderNumber($tenantId);

            $resp = $client->post('/whatsapp/1/message/text', [
                'from' => $sender,
                'to'   => $to,
                'content' => ['text' => $text],
            ]);

            if ($resp->successful()) {
                $mid = $resp->json('messages.0.messageId', $resp->json('messageId'));
                Log::channel('whatsapp')->info('infobip.text.sent', ['to' => $to, 'messageId' => $mid]);
                return ['ok' => true, 'message_id' => $mid, 'error' => null];
            }

            $error = $resp->json('requestError.serviceException.text', $resp->body());
            Log::channel('whatsapp')->warning('infobip.text.failed', ['to' => $to, 'status' => $resp->status(), 'error' => $error]);
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('infobip.text.exception', ['to' => $to, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components, int $tenantId): array
    {
        try {
            $client = $this->makeClient();
            $sender = $this->senderNumber($tenantId);

            // Infobip template format
            $payload = [
                'messages' => [[
                    'from'    => $sender,
                    'to'      => $to,
                    'content' => [
                        'templateName' => $templateName,
                        'templateData' => [
                            'body' => ['placeholders' => $this->extractPlaceholders($components)],
                        ],
                        'language' => $languageCode,
                    ],
                ]],
            ];

            $resp = $client->post('/whatsapp/1/message/template', $payload);

            if ($resp->successful()) {
                $mid = $resp->json('messages.0.messageId');
                Log::channel('whatsapp')->info('infobip.template.sent', ['to' => $to, 'template' => $templateName, 'messageId' => $mid]);
                return ['ok' => true, 'message_id' => $mid, 'error' => null];
            }

            $error = $resp->json('requestError.serviceException.text', $resp->body());
            Log::channel('whatsapp')->warning('infobip.template.failed', ['to' => $to, 'error' => $error]);
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->error('infobip.template.exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function sendMedia(string $to, string $mediaType, string $url, ?string $caption, int $tenantId): array
    {
        try {
            $client = $this->makeClient();
            $sender = $this->senderNumber($tenantId);

            $endpoint = match ($mediaType) {
                'image'    => '/whatsapp/1/message/image',
                'video'    => '/whatsapp/1/message/video',
                'audio'    => '/whatsapp/1/message/audio',
                'document' => '/whatsapp/1/message/document',
                default    => '/whatsapp/1/message/document',
            };

            $content = ['mediaUrl' => $url];
            if ($caption) $content['caption'] = $caption;

            $resp = $client->post($endpoint, [
                'from' => $sender,
                'to'   => $to,
                'content' => $content,
            ]);

            if ($resp->successful()) {
                $mid = $resp->json('messages.0.messageId', $resp->json('messageId'));
                return ['ok' => true, 'message_id' => $mid, 'error' => null];
            }
            return ['ok' => false, 'message_id' => null, 'error' => $resp->json('requestError.serviceException.text', $resp->body())];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function getTemplates(int $tenantId): array
    {
        return Cache::remember("whatsapp_templates_infobip_{$tenantId}", 300, function () use ($tenantId) {
            try {
                $client = $this->makeClient();
                $sender = $this->senderNumber($tenantId);

                $resp = $client->get('/whatsapp/2/senders/' . urlencode($sender) . '/templates');

                if ($resp->successful()) {
                    $templates = $resp->json('templates', []);
                    // Normalizar para formato compatível com Meta
                    return array_map(fn ($t) => [
                        'name'     => $t['name'] ?? '',
                        'language' => $t['language'] ?? 'pt_BR',
                        'status'   => strtoupper($t['status'] ?? 'APPROVED'),
                        'category' => $t['category'] ?? 'MARKETING',
                        'components' => $t['structure']['body']['text'] ?? '',
                    ], $templates);
                }
                return [];
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('infobip.templates.exception', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Extrai placeholders do formato Meta components para array simples Infobip.
     */
    private function extractPlaceholders(array $components): array
    {
        $placeholders = [];
        foreach ($components as $comp) {
            if (($comp['type'] ?? '') === 'body') {
                foreach ($comp['parameters'] ?? [] as $param) {
                    $placeholders[] = $param['text'] ?? '';
                }
            }
        }
        return $placeholders;
    }
}
