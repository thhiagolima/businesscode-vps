<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class TenantApiTokensController extends Controller
{
    private const SENTINEL = AppServiceProvider::SERVER_TO_SERVER_ABILITY;

    private static function publicAbilities(?array $abilities): array
    {
        return array_values(array_diff($abilities ?? [], [self::SENTINEL]));
    }

    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);

        $userIds = User::where('tenant_id', $tenant)->pluck('id');
        $users   = User::whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        $tokens = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->orderByDesc('id')
            ->get(['id', 'tokenable_id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']);

        $payload = $tokens->map(fn ($tok) => [
            'id'           => $tok->id,
            'name'         => $tok->name,
            'abilities'    => self::publicAbilities($tok->abilities),
            'last_used_at' => $tok->last_used_at,
            'expires_at'   => $tok->expires_at,
            'created_at'   => $tok->created_at,
            'user'         => $users->get($tok->tokenable_id)?->only(['id', 'name', 'email']),
        ])->values();

        return ApiResponse::success($payload);
    }

    public function store(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);

        $data = $request->validate([
            'user_id'    => ['required', 'integer'],
            'name'       => ['required', 'string', 'max:120'],
            'abilities'  => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $user = User::where('id', $data['user_id'])->where('tenant_id', $tenant)->first();
        if (!$user) {
            return ApiResponse::error(
                'Usuário não pertence a este tenant.',
                ['user_id' => 'invalid'],
                422,
            );
        }

        $expiresAt = !empty($data['expires_at'])
            ? \Carbon\Carbon::parse($data['expires_at'])
            : null;

        $storedAbilities = array_values(array_unique(
            array_merge($data['abilities'], [self::SENTINEL])
        ));
        $created = $user->createToken($data['name'], $storedAbilities, $expiresAt);

        AdminAuditLogger::log('api_token', 'create', $tenant, $created->accessToken->id, [
            'target_user_id' => $user->id,
            'name'           => $data['name'],
            'abilities'      => $data['abilities'],
            'expires_at'     => $expiresAt?->toIso8601String(),
        ]);

        return ApiResponse::success([
            'id'         => $created->accessToken->id,
            'token'      => $created->plainTextToken,
            'abilities'  => self::publicAbilities($created->accessToken->abilities),
            'expires_at' => $created->accessToken->expires_at,
        ], 'Token criado — exibido uma única vez', [], 201);
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);

        $userIds = User::where('tenant_id', $tenant)->pluck('id');

        $token = PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->where('id', $id)
            ->first();

        if (!$token) {
            return ApiResponse::error('Token não encontrado para este tenant.', [], 404);
        }

        $meta = ['target_user_id' => $token->tokenable_id, 'name' => $token->name];
        $token->delete();

        AdminAuditLogger::log('api_token', 'revoke', $tenant, $id, $meta);

        return ApiResponse::success([], 'Token revogado');
    }
}
