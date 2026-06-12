<?php

namespace App\Jobs\Middleware;

use App\Services\TenantContext;

class SetTenantContext
{
    public function __construct(private int $tenantId) {}

    public function handle(object $job, callable $next): void
    {
        TenantContext::set($this->tenantId);
        try {
            $next($job);
        } finally {
            TenantContext::clear();
        }
    }
}
