<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WhatsAppPhoneNumber;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WhatsAppSettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private WhatsAppService $whatsapp) {}

    public function index(Request $request)
    {
        $tenantId = $request->query('tenant_id', auth()->user()->tenant_id);

        return ApiResponse::success([
            'has_access_token' => !empty($this->settings->get($tenantId, 'whatsapp', 'access_token', '')),
            'phone_number_id'  => $this->settings->get($tenantId, 'whatsapp', 'phone_number_id', ''),
            'waba_id'          => $this->settings->get($tenantId, 'whatsapp', 'waba_id', ''),
            'verify_token'     => $this->settings->getGlobal('whatsapp', 'verify_token', ''),
        ]);
    }

    public function update(Request $request)
    {
        $tenantId = $request->input('tenant_id', auth()->user()->tenant_id);

        $data = $request->validate([
            'phone_number_id' => ['nullable', 'string'],
            'waba_id'         => ['nullable', 'string'],
            'access_token'    => ['nullable', 'string'],
            'verify_token'    => ['nullable', 'string'],
            'app_secret'      => ['nullable', 'string'],
        ]);

        $map = [
            'phone_number_id' => 'string',
            'waba_id'         => 'string',
            'access_token'    => 'encrypted',
            'app_secret'      => 'encrypted',
        ];

        foreach ($map as $key => $type) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '********') {
                $this->settings->upsert($tenantId, 'whatsapp', $key, $data[$key], $type);
            }
        }

        if (array_key_exists('verify_token', $data) && $data['verify_token'] !== null) {
            $this->settings->upsertGlobal('whatsapp', 'verify_token', $data['verify_token'], 'string');
        }

        return ApiResponse::success([], 'Atualizado');
    }

    public function test(Request $request)
    {
        $tenantId = $request->input('tenant_id', auth()->user()->tenant_id);
        $result = $this->whatsapp->testConnection($tenantId);

        if (!$result['ok']) {
            return ApiResponse::error('Falha no teste de conexão WhatsApp: ' . ($result['error'] ?? ''), [], 422);
        }

        if ($result['phone_display']) {
            $phoneNumberId = $this->settings->get($tenantId, 'whatsapp', 'phone_number_id', '');
            if ($phoneNumberId) {
                WhatsAppPhoneNumber::updateOrCreate(
                    ['phone_number_id' => $phoneNumberId],
                    ['tenant_id' => $tenantId, 'phone_display' => $result['phone_display']]
                );
            }
        }

        return ApiResponse::success($result, 'Conexão OK');
    }

    public function syncTemplates(Request $request)
    {
        $tenantId = $request->input('tenant_id', auth()->user()->tenant_id);

        Cache::forget("whatsapp_templates_{$tenantId}");
        $templates = $this->whatsapp->getTemplates($tenantId);

        return ApiResponse::success([
            'count'     => count($templates),
            'templates' => $templates,
        ], 'Templates sincronizados');
    }
}
