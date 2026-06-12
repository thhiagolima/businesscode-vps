<?php

use App\Console\Commands\DispatchScheduledCampaigns;
use App\Console\Commands\FunnelTickCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verifica e dispara campanhas agendadas a cada minuto
Schedule::command(DispatchScheduledCampaigns::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Process waiting funnel executions whose wait_until has passed
Schedule::command(FunnelTickCommand::class)
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
