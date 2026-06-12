<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\SyncElevenLabsVoicesJob;
use App\Models\ElevenLabsVoice;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ElevenLabsSettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private ElevenLabsService $service) {}

    public function index()
    {
        $hasKey = !empty($this->settings->getGlobal('elevenlabs', 'api_key', ''));
        $voicesCount = ElevenLabsVoice::where('is_active', true)->count();
        $lastSync = $this->settings->getGlobal('elevenlabs', 'last_sync_at', null);
        return ApiResponse::success([
            'has_api_key'      => $hasKey,
            'model_id'         => $this->settings->getGlobal('elevenlabs', 'model_id', 'eleven_multilingual_v2'),
            'cost_per_char'    => $this->settings->getGlobal('elevenlabs', 'cost_per_char', '0.0003'),
            'sale_per_char'    => $this->settings->getGlobal('elevenlabs', 'sale_per_char', '0.001'),
            'credits_per_char' => $this->settings->getGlobal('elevenlabs', 'credits_per_char', '1'),
            'voices_count'     => $voicesCount,
            'last_sync_at'     => $lastSync,
        ], 'OK');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'api_key'          => ['nullable', 'string'],
            'model_id'         => ['nullable', 'string'],
            'cost_per_char'    => ['nullable', 'numeric', 'min:0'],
            'sale_per_char'    => ['nullable', 'numeric', 'min:0'],
            'credits_per_char' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (array_key_exists('api_key', $data) && $data['api_key'] !== null && $data['api_key'] !== '********') {
            $this->settings->upsertGlobal('elevenlabs', 'api_key', $data['api_key'], 'encrypted');
        }
        if (array_key_exists('model_id', $data) && $data['model_id'] !== null) {
            $this->settings->upsertGlobal('elevenlabs', 'model_id', $data['model_id'], 'string');
        }
        if (array_key_exists('cost_per_char', $data) && $data['cost_per_char'] !== null) {
            $this->settings->upsertGlobal('elevenlabs', 'cost_per_char', $data['cost_per_char'], 'string');
        }
        if (array_key_exists('sale_per_char', $data) && $data['sale_per_char'] !== null) {
            $this->settings->upsertGlobal('elevenlabs', 'sale_per_char', $data['sale_per_char'], 'string');
        }
        if (array_key_exists('credits_per_char', $data) && $data['credits_per_char'] !== null) {
            $this->settings->upsertGlobal('elevenlabs', 'credits_per_char', $data['credits_per_char'], 'string');
        }

        return ApiResponse::success([], 'Atualizado');
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'api_key' => ['nullable', 'string'],
        ]);
        $result = $this->service->testConnection($data['api_key'] ?? null);
        if (!($result['ok'] ?? false)) {
            return ApiResponse::error('Falha no teste de conexão ElevenLabs', $result, 422);
        }
        return ApiResponse::success($result, 'Conexão OK');
    }

    public function syncVoices()
    {
        SyncElevenLabsVoicesJob::dispatch();
        return ApiResponse::success([], 'Sincronização iniciada.');
    }

    public function voices()
    {
        $voices = ElevenLabsVoice::where('is_active', true)
            ->orderBy('name')
            ->get(['voice_id','name','category','gender','accent','language','preview_url','labels','is_active']);
        return ApiResponse::success($voices);
    }
}
