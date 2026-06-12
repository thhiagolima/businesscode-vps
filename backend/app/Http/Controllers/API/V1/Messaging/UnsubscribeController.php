<?php

namespace App\Http\Controllers\API\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Contact;
use App\Models\MessageDispatch;
use App\Services\Messaging\OptOutService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UnsubscribeController extends Controller
{
    public function __construct(private OptOutService $optOuts) {}

    public function __invoke(Request $request, string $token): Response
    {
        // Transactional email dispatch (MessagingService API).
        $dispatch = MessageDispatch::withoutGlobalScopes()
            ->where('unsubscribe_token', $token)
            ->first();

        if ($dispatch && $dispatch->channel === 'email') {
            if (! $dispatch->unsubscribe_consumed_at) {
                $this->optOuts->add(
                    $dispatch->tenant_id, 'email', $dispatch->to, 'email_link', $dispatch->id
                );
                $dispatch->update(['unsubscribe_consumed_at' => now()]);
            }
            return $this->successResponse();
        }

        // Campaign email dispatch (SendCampaignBatchJob).
        $campaignDispatch = CampaignDispatch::withoutGlobalScopes()
            ->where('unsubscribe_token', $token)
            ->first();

        if ($campaignDispatch) {
            $campaign = Campaign::withoutGlobalScopes()->find($campaignDispatch->campaign_id);
            if (! $campaign || $campaign->type !== 'email') {
                abort(404);
            }
            $email = null;
            if ($campaignDispatch->contact_id) {
                $email = Contact::withoutGlobalScopes()->find($campaignDispatch->contact_id)?->email;
            }
            $email = $email ?? $campaignDispatch->phone;
            if (! $email) {
                abort(404);
            }
            if (! $campaignDispatch->unsubscribe_consumed_at) {
                $this->optOuts->add(
                    $campaignDispatch->tenant_id, 'email', $email, 'email_link', null
                );
                $campaignDispatch->update(['unsubscribe_consumed_at' => now()]);
            }
            return $this->successResponse();
        }

        abort(404);
    }

    private function successResponse(): Response
    {
        return response('<h1>Você foi descadastrado</h1><p>Não receberá mais emails desta lista.</p>')
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
