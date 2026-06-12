<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlansController extends Controller
{
    public function index()
    {
        return ApiResponse::success(Plan::orderBy('id', 'asc')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                   => ['required', 'string', 'max:255'],
            'slug'                   => ['required', 'string', 'max:100', 'unique:plans,slug'],
            'price_monthly'          => ['required', 'numeric', 'min:0', 'max:99999'],
            'included_balance_cents' => ['required', 'integer', 'min:0', 'max:99999999'],
            'max_contacts'           => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'max_campaigns'          => ['nullable', 'integer', 'min:0', 'max:99999'],
            'overage_rate_sms'       => ['nullable', 'numeric', 'min:0', 'max:999'],
            'overage_rate_voice'     => ['nullable', 'numeric', 'min:0', 'max:999'],
            'overage_rate_email'     => ['nullable', 'numeric', 'min:0', 'max:999'],
            'overage_rate_ai'        => ['nullable', 'numeric', 'min:0', 'max:999'],
            'features'               => ['nullable', 'array', 'max:50'],
            'features.*'             => ['string', 'max:255'],
            'is_active'              => ['nullable', 'boolean'],
        ]);

        $plan = Plan::create($data);
        return ApiResponse::success($plan, 'Plano criado', [], 201);
    }

    public function update(Request $request, int $id)
    {
        $plan = Plan::findOrFail($id);
        $data = $request->validate([
            'name'                   => ['sometimes', 'string', 'max:255'],
            'slug'                   => ['sometimes', 'string', 'max:100', 'unique:plans,slug,'.$plan->id],
            'price_monthly'          => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'included_balance_cents' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'max_contacts'           => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'max_campaigns'          => ['sometimes', 'integer', 'min:0', 'max:99999'],
            'overage_rate_sms'       => ['sometimes', 'numeric', 'min:0', 'max:999'],
            'overage_rate_voice'     => ['sometimes', 'numeric', 'min:0', 'max:999'],
            'overage_rate_email'     => ['sometimes', 'numeric', 'min:0', 'max:999'],
            'overage_rate_ai'        => ['sometimes', 'numeric', 'min:0', 'max:999'],
            'features'               => ['nullable', 'array', 'max:50'],
            'features.*'             => ['string', 'max:255'],
            'is_active'              => ['nullable', 'boolean'],
        ]);
        $plan->update($data);
        return ApiResponse::success($plan, 'Plano atualizado');
    }

    public function destroy(int $id)
    {
        $plan = Plan::findOrFail($id);
        $tenantsUsing = \App\Models\Tenant::withoutGlobalScopes()->where('plan_id', $plan->id)->count();
        if ($tenantsUsing > 0) {
            return ApiResponse::error("Não é possível excluir plano com {$tenantsUsing} tenant(s) vinculado(s).", [], 422);
        }
        $plan->delete();
        return ApiResponse::success([], 'Plano removido');
    }
}
