<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    public function getGlobal(string $group, string $key, mixed $default = null): mixed
    {
        $cacheKey = "settings:global:{$group}:{$key}";

        return Cache::remember($cacheKey, 300, function () use ($group, $key, $default) {
            $setting = Setting::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if (!$setting) return $default;

            try {
                return match ($setting->type) {
                    'encrypted' => decrypt($setting->value),
                    'json'      => json_decode($setting->value, true),
                    'boolean'   => filter_var($setting->value, FILTER_VALIDATE_BOOL),
                    default     => $setting->value,
                };
            } catch (\Throwable $e) {
                return $default;
            }
        });
    }

    public function upsertGlobal(string $group, string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'encrypted' => encrypt($value),
            'json'      => json_encode($value),
            'boolean'   => $value ? 'true' : 'false',
            default     => (string) $value,
        };

        $exists = Setting::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('group', $group)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            Setting::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->where('group', $group)
                ->where('key', $key)
                ->update([
                    'value'      => $stored,
                    'type'       => $type,
                    'updated_at' => now(),
                ]);
        } else {
            Setting::withoutGlobalScopes()->insert([
                'tenant_id'  => null,
                'group'      => $group,
                'key'        => $key,
                'value'      => $stored,
                'type'       => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget("settings:global:{$group}:{$key}");
    }

    public function get(int $tenantId, string $group, string $key, mixed $default = null): mixed
    {
        $cacheKey = "settings:{$tenantId}:{$group}:{$key}";

        return Cache::remember($cacheKey, 300, function () use ($tenantId, $group, $key, $default) {
            $setting = Setting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('group', $group)
                ->where('key', $key)
                ->first();

            if (!$setting) return $default;

            try {
                return match ($setting->type) {
                    'encrypted' => decrypt($setting->value),
                    'json'      => json_decode($setting->value, true),
                    'boolean'   => filter_var($setting->value, FILTER_VALIDATE_BOOL),
                    default     => $setting->value,
                };
            } catch (\Throwable $e) {
                return $default;
            }
        });
    }

    public function upsert(int $tenantId, string $group, string $key, mixed $value, string $type = 'string'): void
    {
        $stored = match ($type) {
            'encrypted' => encrypt($value),
            'json'      => json_encode($value),
            'boolean'   => $value ? 'true' : 'false',
            default     => (string) $value,
        };

        $exists = Setting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('group', $group)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            Setting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('group', $group)
                ->where('key', $key)
                ->update([
                    'value'      => $stored,
                    'type'       => $type,
                    'updated_at' => now(),
                ]);
        } else {
            Setting::withoutGlobalScopes()->insert([
                'tenant_id'  => $tenantId,
                'group'      => $group,
                'key'        => $key,
                'value'      => $stored,
                'type'       => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget("settings:{$tenantId}:{$group}:{$key}");
    }
}

