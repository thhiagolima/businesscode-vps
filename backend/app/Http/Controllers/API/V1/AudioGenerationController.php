<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AudioGeneration;
use App\Models\Campaign;
use App\Models\ElevenLabsVoice;
use App\Services\ElevenLabs\ElevenLabsService;
use Illuminate\Http\Request;

class AudioGenerationController extends Controller
{
    use Concerns\ChecksTenant;

    public function __construct(private ElevenLabsService $tts) {}

    public function generate(int $campaignId, Request $request)
    {
        $campaign = Campaign::findOrFail($campaignId);
        $this->ensureTenantOwns($campaign);

        // Validar payload
        $data = $request->validate([
            'script'   => ['required', 'string', 'min:10', 'max:2000'],
            'voice_id' => ['required', 'string', 'exists:elevenlabs_voices,voice_id'],
        ]);

        // Garantir campanha de voz em rascunho
        if ($campaign->type !== 'voice') {
            return ApiResponse::error('Apenas campanhas de voz podem gerar áudio.', [], 422);
        }
        if ($campaign->status !== 'draft') {
            return ApiResponse::error('Apenas rascunhos podem gerar áudio.', [], 422);
        }

        // Gerar áudio
        $generation = $this->tts->generateAudio(
            $data['script'],
            $data['voice_id'],
            $campaign->id,
            auth()->user()->tenant_id
        );

        // Atualizar campanha com URL
        $campaign->update(['audio_url' => $generation->audio_url]);

        return ApiResponse::success($generation->fresh(), 'Áudio gerado');
    }

    public function index(int $campaignId)
    {
        $campaign = Campaign::findOrFail($campaignId);
        $this->ensureTenantOwns($campaign);
        $items = AudioGeneration::where('campaign_id', $campaignId)
            ->orderByDesc('created_at')
            ->get();
        return ApiResponse::success($items);
    }

    public function show(int $id)
    {
        $gen = AudioGeneration::findOrFail($id);
        $this->ensureTenantOwns($gen);
        return ApiResponse::success($gen);
    }

    public function refreshUrl(int $id)
    {
        $gen = AudioGeneration::findOrFail($id);
        $this->ensureTenantOwns($gen);
        $url = $this->tts->refreshAudioUrl($gen);
        return ApiResponse::success(['audio_url' => $url], 'URL renovada');
    }

    public function voices()
    {
        $voices = ElevenLabsVoice::where('is_active', true)
            ->orderBy('name')
            ->get(['voice_id','name','category','gender','accent','language','preview_url','labels','is_active']);
        return ApiResponse::success($voices);
    }
}
