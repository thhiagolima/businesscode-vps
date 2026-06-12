<?php
namespace App\Services\Messaging;

use App\Models\Tenant;
use Carbon\Carbon;

class QuietHoursService
{
    public function isQuietHour(Tenant $tenant): bool
    {
        $plan = $tenant->plan ?? null;
        if ($plan && ! $plan->quiet_hours_enabled) {
            return false;
        }
        [$start, $end, $tz] = $this->windowFor($tenant);
        $now = Carbon::now($tz);
        $startToday = $now->copy()->setTimeFromTimeString($start);
        $endToday   = $now->copy()->setTimeFromTimeString($end);
        if ($startToday->gt($endToday)) {
            return $now->gte($startToday) || $now->lt($endToday);
        }
        return $now->gte($startToday) && $now->lt($endToday);
    }

    public function nextValidTime(Tenant $tenant): Carbon
    {
        [$start, $end, $tz] = $this->windowFor($tenant);
        $now = Carbon::now($tz);

        if (! $this->isQuietHour($tenant)) {
            return $now;
        }

        $endToday = $now->copy()->setTimeFromTimeString($end);
        if ($now->lt($endToday)) {
            return $endToday;
        }
        return $endToday->addDay();
    }

    private function windowFor(Tenant $tenant): array
    {
        $plan = $tenant->plan ?? null;
        $start = $plan->quiet_hours_start ?? config('messaging.quiet_hours.default_start');
        $end   = $plan->quiet_hours_end   ?? config('messaging.quiet_hours.default_end');
        $tz    = $plan->quiet_hours_timezone ?? config('messaging.quiet_hours.default_timezone');
        return [(string) $start, (string) $end, (string) $tz];
    }
}
