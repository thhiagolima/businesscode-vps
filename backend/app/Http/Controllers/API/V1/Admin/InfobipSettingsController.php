<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class InfobipSettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private InfobipService $infobip) {}

    public function index()
    {
        $hasKey = !empty($this->settings->getGlobal('infobip', 'api_key', ''));
        return ApiResponse::success([
            'has_api_key'  => $hasKey,
            'base_url'     => $this->settings->getGlobal('infobip', 'base_url', 'api.infobip.com'),
            'sender_sms'   => $this->settings->getGlobal('infobip', 'sender_sms', 'BusinessCode'),
            'sender_voice' => $this->settings->getGlobal('infobip', 'sender_voice', ''),
            'sender_email' => $this->settings->getGlobal('infobip', 'sender_email', ''),
        ], 'OK');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'api_key'      => ['nullable', 'string'],
            'base_url'     => ['nullable', 'string'],
            'sender_sms'   => ['nullable', 'string'],
            'sender_voice' => ['nullable', 'string'],
            'sender_email' => ['nullable', 'email'],
        ]);

        if (array_key_exists('api_key', $data) && $data['api_key'] !== null && $data['api_key'] !== '********') {
            $this->settings->upsertGlobal('infobip', 'api_key', $data['api_key'], 'encrypted');
        }
        if (array_key_exists('base_url', $data) && $data['base_url'] !== null) {
            $this->settings->upsertGlobal('infobip', 'base_url', $data['base_url'], 'string');
        }
        if (array_key_exists('sender_sms', $data) && $data['sender_sms'] !== null) {
            $this->settings->upsertGlobal('infobip', 'sender_sms', $data['sender_sms'], 'string');
        }
        if (array_key_exists('sender_voice', $data) && $data['sender_voice'] !== null) {
            $this->settings->upsertGlobal('infobip', 'sender_voice', $data['sender_voice'], 'string');
        }
        if (array_key_exists('sender_email', $data) && $data['sender_email'] !== null) {
            $this->settings->upsertGlobal('infobip', 'sender_email', $data['sender_email'], 'string');
        }

        return ApiResponse::success([], 'Atualizado');
    }

    public function test(Request $request)
    {
        $data = $request->validate([
            'api_key'  => ['nullable', 'string'],
            'base_url' => ['nullable', 'string'],
        ]);
        $result = $this->infobip->testConnection($data['api_key'] ?? null, $data['base_url'] ?? null);
        if (!($result['ok'] ?? false)) {
            return ApiResponse::error('Falha no teste de conexão Infobip', $result, 422);
        }
        return ApiResponse::success($result, 'Conexão OK');
    }
}
