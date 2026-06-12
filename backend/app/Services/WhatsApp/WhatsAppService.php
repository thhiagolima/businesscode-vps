<?php

namespace App\Services\WhatsApp;

use App\Services\SettingsService;

/**
 * Facade que resolve o provider correto (Meta ou Infobip) por tenant.
 * O resto do sistema chama este service sem saber qual provider está por trás.
 */
class WhatsAppService implements WhatsAppProviderInterface
{
    private array $providers = [];

    public function __construct(private SettingsService $settings) {}

    /**
     * Resolve o provider para o tenant.
     * - 'meta' → MetaWhatsAppProvider (tenant configura sua WABA)
     * - 'infobip' → InfobipWhatsAppProvider (admin atribui número)
     */
    private function resolve(int $tenantId): WhatsAppProviderInterface
    {
        if (isset($this->providers[$tenantId])) {
            return $this->providers[$tenantId];
        }

        $provider = $this->settings->get($tenantId, 'whatsapp', 'provider', 'meta');

        $instance = match ($provider) {
            'infobip' => new InfobipWhatsAppProvider($this->settings),
            default   => new MetaWhatsAppProvider($this->settings),
        };

        $this->providers[$tenantId] = $instance;
        return $instance;
    }

    public function testConnection(int $tenantId): array
    {
        return $this->resolve($tenantId)->testConnection($tenantId);
    }

    public function sendText(string $to, string $text, int $tenantId): array
    {
        return $this->resolve($tenantId)->sendText($to, $text, $tenantId);
    }

    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components, int $tenantId): array
    {
        return $this->resolve($tenantId)->sendTemplate($to, $templateName, $languageCode, $components, $tenantId);
    }

    public function sendMedia(string $to, string $mediaType, string $url, ?string $caption, int $tenantId): array
    {
        return $this->resolve($tenantId)->sendMedia($to, $mediaType, $url, $caption, $tenantId);
    }

    public function getTemplates(int $tenantId): array
    {
        return $this->resolve($tenantId)->getTemplates($tenantId);
    }

    /**
     * Retorna o nome do provider ativo para o tenant.
     */
    public function getProviderName(int $tenantId): string
    {
        return $this->settings->get($tenantId, 'whatsapp', 'provider', 'meta');
    }
}
