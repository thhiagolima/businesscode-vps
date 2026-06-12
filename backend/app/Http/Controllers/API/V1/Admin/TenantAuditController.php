<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantAuditController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $request->validate([
            'action'  => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
        ]);

        $q = AuditLog::where('tenant_id', $tenant);
        if ($a = $request->input('action'))   $q->where('action', $a);
        if ($u = $request->input('user_id'))  $q->where('user_id', $u);
        if ($f = $request->input('from'))     $q->where('created_at', '>=', $f);
        if ($to = $request->input('to'))      $q->where('created_at', '<=', $to);

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(50));
    }
}
