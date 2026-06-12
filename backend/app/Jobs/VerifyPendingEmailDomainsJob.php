<?php

namespace App\Jobs;

use App\Models\EmailSenderDomain;
use App\Services\Messaging\EmailDomainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifyPendingEmailDomainsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(EmailDomainService $svc): void
    {
        EmailSenderDomain::query()
            ->withoutGlobalScopes()
            ->whereIn('status', ['pending', 'verifying'])
            ->where('verification_attempts', '<', EmailDomainService::MAX_VERIFICATION_ATTEMPTS)
            ->chunk(50, function ($domains) use ($svc) {
                foreach ($domains as $domain) {
                    try {
                        $svc->verify($domain);
                    } catch (\Throwable $e) {
                        Log::channel('infobip')->warning('email_domain.verify.cron_failed', [
                            'domain' => $domain->domain, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
