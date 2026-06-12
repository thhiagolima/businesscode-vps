<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\CreateEmailDomainRequest;
use App\Models\EmailSenderDomain;
use App\Services\Messaging\EmailDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailDomainsController extends Controller
{
    public function __construct(private EmailDomainService $service) {}

    /**
     * Defense-in-depth tenant check. Even though EmailSenderDomain has the
     * AppliesTenantScope global scope, the controller filters by tenant_id
     * explicitly so the endpoint cannot become IDOR if the scope is removed.
     */
    private function findOwned(int $id): \App\Models\EmailSenderDomain
    {
        $user = auth()->user();
        $query = EmailSenderDomain::query();
        if (! $user || $user->role !== 'superadmin') {
            $query->where('tenant_id', (int) ($user->tenant_id ?? 0));
        }
        return $query->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $domains = EmailSenderDomain::query()
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $domains]);
    }

    public function store(CreateEmailDomainRequest $request): JsonResponse
    {
        $user = $request->user();

        // Pre-check uniqueness within tenant scope (the unique index enforces it,
        // but we want a friendly 422 instead of a DB error)
        $exists = EmailSenderDomain::query()
            ->where('domain', $request->validated('domain'))
            ->exists();
        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validacao',
                'errors'  => ['domain' => ['Este dominio ja esta cadastrado para o seu tenant.']],
            ], 422);
        }

        try {
            $domain = $this->service->register(
                tenantId:    (int) $user->tenant_id,
                domain:      $request->validated('domain'),
                trackOpens:  (bool) $request->validated('tracking_opens', false),
                trackClicks: (bool) $request->validated('tracking_clicks', false),
                userId:      (int) $user->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json(['data' => $domain], 201);
    }

    public function show(int $id): JsonResponse
    {
        $domain = $this->findOwned($id);
        return response()->json(['data' => $domain]);
    }

    public function verify(int $id): JsonResponse
    {
        $domain = $this->findOwned($id);

        try {
            $this->service->verify($domain);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha ao verificar dominio: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json(['data' => $domain->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $domain = $this->findOwned($id);

        $this->service->deleteRemote($domain);

        return response()->json(null, 204);
    }
}
