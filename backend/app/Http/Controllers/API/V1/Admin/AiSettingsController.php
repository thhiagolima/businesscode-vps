<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Ai\GrokService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private GrokService $grok) {}

    public function index()
    {
        $hasKey = !empty($this->settings->getGlobal('ai', 'grok_api_key', ''));
        return ApiResponse::success([
            'has_api_key' => $hasKey,
            'grok_model'  => $this->settings->getGlobal('ai', 'grok_model', 'grok-3-mini'),
        ], 'OK');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'grok_api_key' => ['nullable', 'string'],
            'grok_model'   => ['nullable', 'string'],
        ]);

        if (array_key_exists('grok_api_key', $data) && $data['grok_api_key'] !== null && $data['grok_api_key'] !== '********') {
            $this->settings->upsertGlobal('ai', 'grok_api_key', $data['grok_api_key'], 'encrypted');
        }
        if (array_key_exists('grok_model', $data) && $data['grok_model'] !== null) {
            $this->settings->upsertGlobal('ai', 'grok_model', $data['grok_model'], 'string');
        }

        return ApiResponse::success([], 'Atualizado');
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string'],
        ]);
        $result = $this->grok->testConnection($data['api_key'] ?? null);
        if (!($result['ok'] ?? false)) {
            return ApiResponse::error($result['error'] ?? 'Falha no teste de conexão Grok', $result, 422);
        }
        return ApiResponse::success($result, 'Conexão OK');
    }

    public function models()
    {
        $models = $this->grok->listModels();
        return ApiResponse::success($models);
    }
}
