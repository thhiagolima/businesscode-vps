<?php

namespace App\Services;

class TenantContext
{
    private static ?int $tenantId = null;

    public static function set(?int $tenantId): void
    {
        static::$tenantId = $tenantId;
    }

    public static function get(): ?int
    {
        return static::$tenantId;
    }

    public static function clear(): void
    {
        static::$tenantId = null;
    }
}
