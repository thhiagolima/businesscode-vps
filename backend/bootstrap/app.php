<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $monthlyHour = (int) config('billing.monthly_dispatch_hour', 3);
        $overdueHour = (int) config('billing.overdue_retry_hour', 4);

        $schedule->job(new \App\Jobs\DispatchMonthlyBillingJob)
            ->dailyAt(sprintf('%02d:00', $monthlyHour))
            ->timezone('America/Sao_Paulo')
            ->name('billing.monthly-dispatch')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->job(new \App\Jobs\DispatchOverdueRetryJob)
            ->dailyAt(sprintf('%02d:00', $overdueHour))
            ->timezone('America/Sao_Paulo')
            ->name('billing.overdue-retry')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->job(new \App\Jobs\VerifyPendingEmailDomainsJob)
            ->dailyAt('05:00')
            ->timezone('America/Sao_Paulo')
            ->name('email.verify-pending-domains')
            ->withoutOverlapping(60)
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias([
            'superadmin'    => \App\Http\Middleware\SuperAdmin::class,
            'token.ability' => \App\Http\Middleware\EnsureTokenAbility::class,
            'role'          => \App\Http\Middleware\EnsureRole::class,
            'log.voice'     => \App\Http\Middleware\LogVoiceApiRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 401: Auth missing/invalid — return JSON instead of trying to redirect to web `login` route
        // (project is API-only, no login route registered for Symfony URL generator)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Unauthenticated',
                    'message' => 'Token missing, invalid or expired. Include header "Authorization: Bearer <token>".',
                ], 401);
            }
        });
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação',
                'errors' => $e->errors(),
            ], 422);
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return response()->json(['success' => false, 'message' => 'Recurso não encontrado'], 404);
        });
        // Report every unhandled exception to Sentry if the package is installed
        // and SENTRY_LARAVEL_DSN is configured. No-op otherwise.
        $exceptions->reportable(function (\Throwable $e) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });
        $exceptions->render(function (\Throwable $e) {
            if (app()->environment('production')) {
                \Illuminate\Support\Facades\Log::error('Unhandled exception', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return response()->json(['success' => false, 'message' => 'Erro interno do servidor'], 500);
            }
        });
    })->create();
