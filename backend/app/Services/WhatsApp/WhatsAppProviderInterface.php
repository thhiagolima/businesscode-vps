<?php

namespace App\Services\WhatsApp;

interface WhatsAppProviderInterface
{
    public function testConnection(int $tenantId): array;

    public function sendText(string $to, string $text, int $tenantId): array;

    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components, int $tenantId): array;

    public function sendMedia(string $to, string $mediaType, string $url, ?string $caption, int $tenantId): array;

    public function getTemplates(int $tenantId): array;
}
