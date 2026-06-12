<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiPersona;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function persona()
    {
        $tenantId = auth()->user()->tenant_id;
        $persona = AiPersona::where('tenant_id', $tenantId)->first();
        return ApiResponse::success($persona);
    }

    public function updatePersona(Request $request)
    {
        $data = $request->validate([
            'bot_name'             => ['required', 'string', 'max:50'],
            'tone'                 => ['required', 'in:formal,casual,friendly'],
            'company_name'         => ['required', 'string', 'max:100'],
            'products_services'    => ['required', 'string', 'max:2000'],
            'business_rules'       => ['required', 'string', 'max:2000'],
            'special_instructions' => ['nullable', 'string', 'max:1000'],
            'working_hours'        => ['nullable', 'string', 'max:200'],
        ]);

        $data['status'] = 'pending_approval';
        $data['rejection_reason'] = null;
        $data['approved_by'] = null;
        $data['approved_at'] = null;

        $persona = AiPersona::updateOrCreate(
            ['tenant_id' => auth()->user()->tenant_id],
            $data
        );

        return ApiResponse::success($persona, 'Persona salva e enviada para aprovação');
    }

    public function settings()
    {
        $tenantId = auth()->user()->tenant_id;
        $hasApproved = AiPersona::where('tenant_id', $tenantId)
            ->where('status', 'approved')
            ->exists();

        return ApiResponse::success([
            'mode'                 => $this->settings->get($tenantId, 'chatbot', 'mode', 'suggestion'),
            'enabled'              => (bool) $this->settings->get($tenantId, 'chatbot', 'enabled', false),
            'has_approved_persona' => $hasApproved,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'mode'    => ['nullable', 'in:autonomous,suggestion'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        if ($data['enabled'] ?? false) {
            $hasApproved = AiPersona::where('tenant_id', $tenantId)
                ->where('status', 'approved')
                ->exists();
            if (! $hasApproved) {
                return ApiResponse::error('Persona precisa ser aprovada antes de ativar o chatbot.', [], 422);
            }
        }

        if (array_key_exists('mode', $data) && $data['mode'] !== null) {
            $this->settings->upsert($tenantId, 'chatbot', 'mode', $data['mode'], 'string');
        }
        if (array_key_exists('enabled', $data)) {
            $this->settings->upsert($tenantId, 'chatbot', 'enabled', $data['enabled'] ? '1' : '0', 'boolean');
        }

        return ApiResponse::success([], 'Configurações atualizadas');
    }
}
