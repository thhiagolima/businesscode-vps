<?php

use App\Models\TenantChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenants criados antes do provisionamento automático de canais (ou por
 * caminhos que não o disparavam) ficaram sem nenhuma linha em
 * `tenant_channels`. Sem um canal `enabled`, a regra `licensedChannel` do
 * CampaignsController rejeita toda criação de campanha com 422
 * ("Erro de validação"). Este backfill garante os canais padrão para
 * qualquer tenant que ainda não os tenha. Idempotente via firstOrCreate.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            TenantChannel::provisionDefaults((int) $tenantId);
        }
    }

    public function down(): void
    {
        // Backfill de dados: não há como distinguir com segurança as linhas
        // criadas aqui das já existentes, então o down é intencionalmente no-op.
    }
};
