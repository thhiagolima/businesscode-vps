<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\CreateApiTokenRequest;
use App\Providers\AppServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokensController extends Controller
{
    /**
     * Ability interna que isenta o token do teto global de 24h
     * (config sanctum.expiration). Aplicada a TODO token server-to-server
     * criado aqui, espelhando Admin\TenantApiTokensController. Sem ela, o Guard
     * do Sanctum mata o token 24h após created_at — independente de expires_at.
     */
    private const SENTINEL = AppServiceProvider::SERVER_TO_SERVER_ABILITY;

    /** Remove a sentinela interna antes de exibir as abilities ao usuário. */
    private static function publicAbilities(?array $abilities): array
    {
        return array_values(array_diff($abilities ?? [], [self::SENTINEL]));
    }

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->get(['id', 'name', 'abilities', 'expires_at', 'created_at', 'last_used_at'])
            ->map(fn ($tok) => [
                'id'           => $tok->id,
                'name'         => $tok->name,
                'abilities'    => self::publicAbilities($tok->abilities),
                'expires_at'   => $tok->expires_at,
                'created_at'   => $tok->created_at,
                'last_used_at' => $tok->last_used_at,
            ]);

        return response()->json(['data' => $tokens]);
    }

    public function store(CreateApiTokenRequest $request): JsonResponse
    {
        $user      = $request->user();
        $expiresAt = $request->validated('expires_at')
            ? \Carbon\Carbon::parse($request->validated('expires_at'))
            : null;

        // Server-to-server tokens são imortais por padrão: grava a sentinela para
        // bypassar o teto global de 24h. Só expiram se um expires_at explícito for
        // informado (respeitado pelo callback em AppServiceProvider).
        $storedAbilities = array_values(array_unique(
            array_merge($request->validated('abilities'), [self::SENTINEL])
        ));

        $token = $user->createToken(
            $request->validated('name'),
            $storedAbilities,
            $expiresAt
        );

        return response()->json([
            'id'         => $token->accessToken->id,
            'token'      => $token->plainTextToken,
            'abilities'  => self::publicAbilities($token->accessToken->abilities),
            'expires_at' => $token->accessToken->expires_at,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->where('id', $id)->delete();

        return response()->json(null, 204);
    }
}
