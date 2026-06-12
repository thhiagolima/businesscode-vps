<?php

namespace App\Jobs;

use App\Services\ElevenLabs\ElevenLabsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncElevenLabsVoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function handle(ElevenLabsService $service): void
    {
        $count = $service->syncVoices();
        Log::channel('elevenlabs')->info('voices.sync.job.completed', ['count' => $count]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('elevenlabs')->error('voices.sync.failed', ['error' => $e->getMessage()]);
    }
}
