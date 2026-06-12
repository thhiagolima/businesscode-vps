<?php

namespace App\Jobs;

use App\Jobs\FireOutboundWebhookJob;
use App\Mail\TransactionalMailer;
use App\Models\MessageDispatch;
use App\Services\Billing\BillingService;
use App\Services\Infobip\InfobipService;
use App\Services\Messaging\MessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 600];

    public function __construct(public int $dispatchId)
    {
        $this->onQueue('messaging');
    }

    public function handle(InfobipService $infobip): void
    {
        $dispatch = DB::transaction(function () {
            $d = MessageDispatch::withoutGlobalScopes()->lockForUpdate()->find($this->dispatchId);
            if (! $d || $d->status !== 'queued') {
                return null;
            }
            $d->update(['status' => 'sending']);
            return $d;
        });

        if (! $dispatch) {
            return;
        }

        try {
            $result = match ($dispatch->channel) {
                'sms'   => $infobip->sendSms($dispatch->to, $dispatch->content, $dispatch->from ?? 'InfoSMS'),
                'voice' => $infobip->sendVoice($dispatch->to, $dispatch->content, $dispatch->from ?? 'InfoVoice', $dispatch->audio_url),
                'email' => $dispatch->provider === 'laravel_mail'
                    ? app(TransactionalMailer::class)->send($dispatch)
                    : $infobip->sendEmail(
                        $dispatch->to,
                        $dispatch->subject ?? '(sem assunto)',
                        $dispatch->content,
                        $dispatch->from ?? '',
                        $dispatch->from_name ?? '',
                        $dispatch->reply_to
                      ),
            };
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('send_message.exception', [
                'dispatch_id' => $dispatch->id,
                'tenant_id'   => $dispatch->tenant_id,
                'channel'     => $dispatch->channel,
                'error'       => $e->getMessage(),
            ]);
            if ($this->attempts() >= $this->tries) {
                $this->releaseCredits($dispatch, $e->getMessage(), 'EXCEPTION');
            }
            throw $e;
        }

        if ($result['ok']) {
            $dispatch->update([
                'status'              => 'sent',
                'external_message_id' => $result['message_id'],
                'charged_cents'       => (int) $dispatch->sale_cents,
                'sent_at'             => now(),
            ]);
            FireOutboundWebhookJob::dispatch(
                $dispatch->tenant_id,
                'message.sent',
                app(MessagingService::class)->buildEventPayload($dispatch->fresh() ?? $dispatch)
            );
        } else {
            $this->releaseCredits($dispatch, $result['error'] ?? 'unknown', 'PROVIDER_ERROR');
        }
    }

    private function releaseCredits(MessageDispatch $dispatch, string $errorMessage, string $errorCode): void
    {
        app(BillingService::class)->release(
            $dispatch->tenant_id,
            (int) $dispatch->sale_cents,
            'message_dispatch',
            $dispatch->id
        );
        $dispatch->update([
            'status'        => 'failed',
            'error_code'    => $errorCode,
            'error_message' => mb_substr($errorMessage, 0, 500),
            'failed_at'     => now(),
            'charged_cents' => 0,
        ]);
        FireOutboundWebhookJob::dispatch(
            $dispatch->tenant_id,
            'message.failed',
            app(MessagingService::class)->buildEventPayload($dispatch->fresh() ?? $dispatch)
        );
    }
}
