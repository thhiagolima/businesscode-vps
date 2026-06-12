<?php

namespace App\Console\Commands;

use App\Providers\AppServiceProvider;
use Laravel\Sanctum\Console\Commands\PruneExpired as SanctumPruneExpired;
use Laravel\Sanctum\Sanctum;

class PruneExpiredTokens extends SanctumPruneExpired
{
    public function handle()
    {
        $model = Sanctum::personalAccessTokenModel();
        $hours = $this->option('hours');

        $this->components->info(
            'Pruning tokens with expired expires_at timestamps'
        );
        $model::where('expires_at', '<', now()->subHours($hours))->delete();

        if ($expiration = config('sanctum.expiration')) {
            $this->components->info(
                'Pruning tokens with expired expiration value based on configuration file'
            );
            $sentinel = AppServiceProvider::SERVER_TO_SERVER_ABILITY;
            $model::where('created_at', '<', now()->subMinutes($expiration + ($hours * 60)))
                ->where(function ($q) use ($sentinel) {
                    $q->whereNull('abilities')
                      ->orWhere('abilities', 'NOT LIKE', '%'.$sentinel.'%');
                })
                ->delete();
        }

        return 0;
    }
}
