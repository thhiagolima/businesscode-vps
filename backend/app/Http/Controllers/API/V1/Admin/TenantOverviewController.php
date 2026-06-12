<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;

class TenantOverviewController extends Controller
{
    public function index(int $tenant)
    {
        $t = Tenant::with('plan')->findOrFail($tenant);

        $kpis = [
            'campaigns_total'        => Campaign::where('tenant_id', $t->id)->count(),
            'campaigns_active'       => Campaign::where('tenant_id', $t->id)
                                                ->whereIn('status', ['scheduled', 'processing', 'running'])
                                                ->count(),
            'contacts_total'         => Contact::where('tenant_id', $t->id)->count(),
            'messages_sent_30d'      => MessageDispatch::where('tenant_id', $t->id)
                                                       ->where('created_at', '>=', now()->subDays(30))
                                                       ->count(),
            'messages_delivered_30d' => MessageDispatch::where('tenant_id', $t->id)
                                                       ->where('status', 'delivered')
                                                       ->where('created_at', '>=', now()->subDays(30))
                                                       ->count(),
            'conversations_open'     => Conversation::where('tenant_id', $t->id)
                                                    ->where('status', 'open')
                                                    ->count(),
            'users_total'            => User::where('tenant_id', $t->id)->count(),
        ];

        return ApiResponse::success([
            'tenant' => $t,
            'kpis'   => $kpis,
        ]);
    }
}
