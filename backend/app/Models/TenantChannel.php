<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class TenantChannel extends Model
{
    use AppliesTenantScope;

    protected $fillable = ['tenant_id', 'channel', 'status', 'config'];
    protected $casts = ['config' => 'array'];

    /**
     * Canais provisionados por padrão ao criar um tenant.
     * Fonte única da verdade — usada no registro, no seeder e no backfill,
     * para que nenhum caminho deixe um tenant sem canais (o que bloqueia
     * a criação de campanhas pela regra `licensedChannel`).
     */
    public const DEFAULT_CHANNELS = [
        'sms'      => 'enabled',
        'voice'    => 'enabled',
        'email'    => 'disabled',
        'whatsapp' => 'disabled',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Garante que o tenant tenha as linhas de canal padrão.
     * Idempotente: usa firstOrCreate, então canais já existentes
     * (e seus status) são preservados — apenas os ausentes são criados.
     */
    public static function provisionDefaults(int $tenantId): void
    {
        foreach (self::DEFAULT_CHANNELS as $channel => $status) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'channel' => $channel],
                ['status' => $status, 'config' => []],
            );
        }
    }

    public static function isAvailable(int $tenantId, string $channel): bool
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('status', 'enabled')
            ->exists();
    }

    public static function getForTenant(int $tenantId): array
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('channel')
            ->toArray();
    }
}
