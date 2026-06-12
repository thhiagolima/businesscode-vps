<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InfobipWhatsAppNumber;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;

class InfobipWhatsAppController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Lista todos os números WhatsApp Infobip (sync + atribuição).
     */
    public function numbers()
    {
        $numbers = InfobipWhatsAppNumber::with('tenant:id,name')->orderBy('number')->get();
        return ApiResponse::success($numbers);
    }

    /**
     * Sincroniza números da API Infobip GET /whatsapp/2/senders.
     */
    public function sync()
    {
        $apiKey  = $this->settings->getGlobal('infobip', 'api_key', '');
        $baseUrl = $this->settings->getGlobal('infobip', 'base_url', 'api.infobip.com');

        if (empty($apiKey)) {
            return ApiResponse::error('Infobip API key não configurada.', [], 422);
        }

        try {
            $resp = Http::withHeaders(['Authorization' => "App {$apiKey}"])
                ->baseUrl("https://{$baseUrl}")
                ->timeout(15)
                ->get('/whatsapp/2/senders');

            if (!$resp->successful()) {
                return ApiResponse::error('Falha ao buscar senders: HTTP ' . $resp->status(), [], 422);
            }

            // V2 retorna {"results": [...]}, V1 retorna [...] direto
            $body = $resp->json();
            $senders = $body['results'] ?? (is_array($body) && !isset($body['results']) ? $body : []);
            $now = now();
            $synced = 0;

            Log::channel('infobip')->info('whatsapp.senders.fetched', ['count' => count($senders)]);

            foreach ($senders as $s) {
                $sender = $s['sender'] ?? $s['number'] ?? '';
                if (empty($sender)) continue;

                $connectionStatus = strtoupper($s['connectionStatus'] ?? 'UNKNOWN');

                InfobipWhatsAppNumber::updateOrCreate(
                    ['sender' => $sender],
                    [
                        'number'       => $s['number'] ?? $sender,
                        'display_name' => $s['displayName'] ?? $s['businessName'] ?? null,
                        'status'       => strtolower($connectionStatus),
                        'synced_at'    => $now,
                    ]
                );
                $synced++;
            }

            Log::channel('infobip')->info('whatsapp.numbers.synced', ['count' => $synced]);
            return ApiResponse::success(['synced' => $synced], "{$synced} números sincronizados");
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('whatsapp.numbers.sync_failed', ['error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao sincronizar: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Atribui um número a um tenant (ou remove atribuição com tenant_id=null).
     */
    public function assign(int $id, Request $request)
    {
        $number = InfobipWhatsAppNumber::findOrFail($id);
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $tenantId = $data['tenant_id'];

        // Se atribuindo a tenant, garantir que nenhum outro número está atribuído ao mesmo tenant
        if ($tenantId) {
            InfobipWhatsAppNumber::where('tenant_id', $tenantId)
                ->where('id', '!=', $number->id)
                ->update(['tenant_id' => null]);

            // Setar provider do tenant para 'infobip'
            $this->settings->upsert($tenantId, 'whatsapp', 'provider', 'infobip', 'string');
        }

        $number->update(['tenant_id' => $tenantId]);

        return ApiResponse::success($number->fresh()->load('tenant:id,name'), 'Número atualizado');
    }
}
