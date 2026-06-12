<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EmailSenderDomain;
use App\Services\Messaging\EmailDomainService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InfobipEmailController extends Controller
{
    public function __construct(
        private EmailDomainService $service,
        private SettingsService $settings,
    ) {}

    /**
     * GET /admin/infobip-email/domains
     * Lists every domain (including unassigned pool), with tenant info eager-loaded.
     */
    public function domains()
    {
        $domains = EmailSenderDomain::withoutGlobalScopes()
            ->with('tenant:id,name')
            ->orderBy('domain')
            ->get();

        return ApiResponse::success($domains);
    }

    /**
     * POST /admin/infobip-email/sync
     * Pulls all domains from Infobip and upserts locally.
     */
    public function sync()
    {
        try {
            $result = $this->service->syncFromInfobip();
        } catch (\App\Exceptions\InfobipNotConfiguredException $e) {
            return ApiResponse::error('Infobip API key não configurada.', [], 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 502);
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('email_domain.sync.exception', ['error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao sincronizar. Verifique os logs.', [], 500);
        }

        return ApiResponse::success($result, "{$result['synced']} domínios sincronizados");
    }

    /**
     * PUT /admin/infobip-email/domains/{id}/assign
     * Body: { tenant_id: int|null }
     *
     * Unlike WhatsApp numbers (1 number ↔ 1 tenant), email domains are N ↔ 1:
     * we do NOT unassign other domains belonging to the same tenant.
     */
    public function assign(int $id, Request $request)
    {
        $domain = EmailSenderDomain::withoutGlobalScopes()->findOrFail($id);

        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $tenantId = $data['tenant_id'] ?? null;

        DB::transaction(function () use ($domain, $tenantId) {
            if ($tenantId !== null) {
                $this->settings->upsert($tenantId, 'email', 'provider', 'infobip', 'string');
            }
            $domain->update(['tenant_id' => $tenantId]);
        });

        return ApiResponse::success($domain->fresh()->load('tenant:id,name'), 'Domínio atualizado');
    }
}
