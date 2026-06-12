<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', fn () => view('api-docs'));

/*
 * Stub `login` route — REQUIRED for Laravel 12 + Sanctum API-only setup.
 *
 * The default Authenticate middleware calls $this->redirectTo($request)
 * inside the AuthenticationException constructor. When the request lacks
 * an Accept: application/json header, redirectTo() resolves route('login')
 * via the URL generator. With no `login` named route registered, the
 * generator throws RouteNotFoundException BEFORE the AuthenticationException
 * is built — bypassing our render handler and surfacing a 500 stacktrace.
 *
 * This stub returns the same 401 JSON our exception handler would, ensuring
 * a clean error regardless of the client's Accept header.
 */
Route::any('/login', function () {
    return response()->json([
        'success' => false,
        'error'   => 'Unauthenticated',
        'message' => 'Token missing, invalid or expired. Include header "Authorization: Bearer <token>".',
    ], 401);
})->name('login');
