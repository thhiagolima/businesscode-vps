<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $roles)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        // Superadmin always passes
        if ($user->role === 'superadmin') {
            return $next($request);
        }

        $allowed = explode('|', $roles);
        if (! in_array($user->role, $allowed, true)) {
            return response()->json(['error' => 'FORBIDDEN', 'required_role' => $roles], 403);
        }

        return $next($request);
    }
}
