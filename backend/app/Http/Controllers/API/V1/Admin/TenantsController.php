<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class TenantsController extends Controller
{
    public function __construct(private BillingService $billing) {}


    public function index()
    {
        $validated = request()->validate([
            'status' => ['nullable', 'in:active,suspended,trial'],
            'search' => ['nullable', 'string', 'min:2', 'max:100'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Tenant::with(['plan', 'enabledChannels:id,tenant_id,channel,status'])->orderBy('id', 'desc');
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['search'])) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('slug', 'like', "%{$s}%");
            });
        }
        $items = $query->paginate(20);
        return ApiResponse::paginated($items);
    }

    public function show(int $id)
    {
        $tenant = Tenant::with('plan')->findOrFail($id);
        return ApiResponse::success($tenant);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'slug'           => ['required', 'string', 'max:100', 'unique:tenants,slug'],
            'plan_id'        => ['nullable', 'exists:plans,id'],
            'balance_cents'  => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'status'         => ['required', 'in:active,suspended,trial'],
            'trial_ends_at'  => ['nullable', 'date'],
            'admin_name'     => ['nullable', 'string', 'max:100'],
            'admin_email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);
        $tenant = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name'          => $data['name'],
                'slug'          => $data['slug'],
                'plan_id'       => $data['plan_id'] ?? null,
                'balance_cents' => $data['balance_cents'] ?? 0,
                'status'        => $data['status'],
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
            ]);
            (new User())->forceFill([
                'name'      => 'Admin '.$tenant->name,
                'email'     => $data['admin_email'],
                'password'  => bcrypt($data['admin_password']),
                'tenant_id' => $tenant->id,
                'role'      => 'admin',
            ])->save();
            // Auto-create default channels (SMS and Email enabled, others disabled)
            foreach (['sms' => 'enabled', 'email' => 'enabled', 'voice' => 'disabled', 'whatsapp' => 'disabled'] as $channel => $status) {
                \App\Models\TenantChannel::create([
                    'tenant_id' => $tenant->id,
                    'channel' => $channel,
                    'status' => $status,
                    'config' => [],
                ]);
            }

            return $tenant;
        });
        return ApiResponse::success($tenant->load('plan'), 'Tenant criado', [], 201);
    }

    public function update(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'slug'          => ['sometimes', 'string', 'max:100', 'unique:tenants,slug,'.$tenant->id],
            'plan_id'       => ['sometimes', 'nullable', 'exists:plans,id'],
            'balance_cents' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'status'        => ['sometimes', 'in:active,suspended,trial'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $tenant->update($data);
        return ApiResponse::success($tenant, 'Tenant atualizado');
    }

    public function addCredits(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $data = $request->validate([
            // `amount` is in CENTS — matches the BillingService manualAdjustment contract.
            'amount'      => ['required', 'integer', 'min:1', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $adminId = $request->user()?->id ?? 0;
        $this->billing->manualAdjustment(
            $tenant->id,
            (int) $data['amount'],
            $data['description'] ?? 'Ajuste manual de saldo (admin)',
            $adminId,
        );
        Log::channel('campaign')->info('balance.add', ['tenant_id' => $tenant->id, 'amount_cents' => $data['amount']]);
        return ApiResponse::success($tenant->fresh(), 'Saldo adicionado');
    }

    public function destroy(int $id)
    {
        $tenant = Tenant::findOrFail($id);
        $activeData = Campaign::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['draft', 'completed', 'failed'])->exists();
        if ($activeData) {
            return ApiResponse::error('Não é possível excluir tenant com campanhas ativas.', [], 422);
        }
        // Red Team finding #16: never let a debtor tenant be deleted —
        // the negative balance would be silently absorbed and (per
        // finding #20) the audit trail erased too. Require regularization
        // (manual adjustment, write-off via credit, or charge) first.
        if ((int) $tenant->balance_cents < 0) {
            return ApiResponse::error(
                'Não é possível excluir tenant com saldo devedor. Realize ajuste manual ou cobrança antes.',
                ['error' => 'NEGATIVE_BALANCE', 'balance_cents' => (int) $tenant->balance_cents],
                422
            );
        }
        $tenant->delete();
        return ApiResponse::success([], 'Tenant removido');
    }
}
