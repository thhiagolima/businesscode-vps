<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantUsersController extends Controller
{
    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $list = User::where('tenant_id', $tenant)
            ->select(['id', 'name', 'email', 'role', 'status', 'force_password_reset', 'created_at'])
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::paginated($list);
    }

    public function store(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'in:user,admin,finance'],
        ]);

        // `tenant_id` and `role` are not mass-assignable by design (see User::$fillable
        // comment). We assign them via forceFill from this trusted superadmin endpoint
        // so the body cannot smuggle an arbitrary tenant_id or escalate to superadmin.
        $user = (new User())->forceFill([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'tenant_id' => $tenant,
            'role'      => $data['role'],
            'status'    => 'active',
        ]);
        $user->save();

        AdminAuditLogger::log('user', 'create', $tenant, $user->id, [
            'email' => $user->email,
            'role'  => $user->role,
        ]);

        return ApiResponse::success(
            $user->only(['id', 'name', 'email', 'role', 'status']),
            'Usuário criado',
            [],
            201,
        );
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $user = User::where('tenant_id', $tenant)->findOrFail($id);

        // Hard-guard: a superadmin account cannot be flipped to suspended via
        // this drill-down endpoint. Lockout protection — only an explicit, manual
        // DB intervention should ever take the platform owner offline.
        if ($user->role === 'superadmin'
            && $request->has('status')
            && $request->input('status') !== 'active'
        ) {
            return ApiResponse::error('Superadmin não pode ser suspenso.', [], 422);
        }

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:100'],
            'email'  => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'   => ['sometimes', 'in:user,admin,finance'],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        $before = $user->only(array_keys($data));

        if (isset($data['role'])) {
            $user->forceFill(['role' => $data['role']]);
        }
        $user->fill(array_diff_key($data, ['role' => true]));
        $user->save();

        // Suspending a user must immediately invalidate every active session/
        // API token so they can't keep operating with a stale Sanctum token.
        if (($data['status'] ?? null) === 'suspended') {
            $user->tokens()->delete();
        }

        AdminAuditLogger::log('user', 'update', $tenant, $id, [
            'before' => $before,
            'after'  => $data,
        ]);

        return ApiResponse::success($user->only(['id', 'name', 'email', 'role', 'status']));
    }

    public function resetPassword(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $user = User::where('tenant_id', $tenant)->findOrFail($id);

        // 16-char random temp password. The clear-text value is returned ONCE
        // in the HTTP response (so support can hand it to the customer) but is
        // never written to logs or the audit metadata.
        $temp = Str::random(16);
        $user->forceFill([
            'password'             => Hash::make($temp),
            'force_password_reset' => true,
        ])->save();

        // Revoke all existing tokens so the old session/credentials become invalid.
        $user->tokens()->delete();

        AdminAuditLogger::log('user', 'password_reset', $tenant, $id, []);

        return ApiResponse::success(
            ['temp_password' => $temp],
            'Senha temporária gerada — exibida uma única vez',
        );
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $user = User::where('tenant_id', $tenant)->findOrFail($id);

        if ($user->role === 'superadmin') {
            return ApiResponse::error('Superadmin não pode ser removido por esta rota.', [], 422);
        }

        $snap = $user->only(['email', 'role']);
        $user->tokens()->delete();
        $user->delete();

        AdminAuditLogger::log('user', 'delete', $tenant, $id, $snap);

        return ApiResponse::success([], 'Usuário removido');
    }
}
