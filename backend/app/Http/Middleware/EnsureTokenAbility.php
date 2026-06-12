<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'tokenCan') || ! $user->tokenCan($ability)) {
            return response()->json([
                'error'    => 'INSUFFICIENT_TOKEN_ABILITY',
                'required' => $ability,
            ], 403);
        }
        return $next($request);
    }
}
