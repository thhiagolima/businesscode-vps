<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantChannel;
use App\Services\Messaging\OptOutService;
use App\Services\Messaging\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InboundWebhookController extends Controller
{
    public function __construct(private OptOutService $optOuts) {}

    public function handle(Request $request): JsonResponse
    {
        $secret   = (string) config('messaging.inbound_webhook_secret');
        $provided = $request->header('Authorization', '');
        $provided = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided;

        if (! $secret || ! hash_equals($secret, $provided)) {
            Log::channel('infobip')->warning('inbound_webhook.unauthorized', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $processed   = 0;
        $inKeywords  = array_map('mb_strtoupper', config('messaging.opt_out_keywords_in', []));
        $outKeywords = array_map('mb_strtoupper', config('messaging.opt_out_keywords_out', []));

        foreach ($request->input('results', []) as $msg) {
            $from = $msg['from'] ?? null;
            $to   = $msg['to']   ?? null;
            $text = mb_strtoupper(trim($msg['text'] ?? ''));
            if (! $from || ! $to) {
                continue;
            }

            $tenantId = $this->resolveTenantByNumber($to);
            if (! $tenantId) {
                Log::channel('infobip')->warning('inbound_webhook.tenant_not_found', ['to' => $to]);
                continue;
            }

            $normalized = $this->normalizePhone($from);
            if (! $normalized) {
                Log::channel('infobip')->warning('inbound_webhook.invalid_from', ['from' => $from]);
                continue;
            }

            if (in_array($text, $inKeywords, true)) {
                $this->optOuts->add($tenantId, 'sms', $normalized, 'sms_stop');
                $processed++;
            } elseif (in_array($text, $outKeywords, true)) {
                $this->optOuts->remove($tenantId, 'sms', $normalized);
                $processed++;
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    private function resolveTenantByNumber(string $to): ?int
    {
        // TenantChannel stores channel + config (no identifier column); search config payload.
        if (! class_exists(TenantChannel::class)) {
            return null;
        }

        $row = TenantChannel::withoutGlobalScopes()
            ->where('channel', 'sms')
            ->where(function ($q) use ($to) {
                $q->where('config->identifier', $to)
                  ->orWhere('config->number', $to)
                  ->orWhere('config->from', $to);
            })
            ->first();

        return $row?->tenant_id;
    }

    private function normalizePhone(string $raw): ?string
    {
        try {
            return PhoneNormalizer::e164($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
