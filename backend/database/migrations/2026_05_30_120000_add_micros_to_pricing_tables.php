<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona precisão sub-centavo nas tabelas de pricing.
 *
 * Motivo: custos reais dos fornecedores têm frações de centavo (SMS R$ 0,0605,
 * Voz R$ 0,03501, Email R$ 0,0043) que arredondados para inteiros de centavos
 * (cost_cents) viram zero ou perdem ~10% de margem.
 *
 * Estratégia: adiciona cost_micros / sale_micros (×10⁵ — 1 micro = 0,00001 reais)
 * ao lado das colunas existentes em centavos. PricingService prefere micros se
 * >0; cents continuam preenchidos como espelho arredondado para retrocompat
 * com relatórios, dashboards e código legado.
 */
return new class extends Migration {
    public function up(): void
    {
        // Global service prices
        Schema::table('service_prices', function (Blueprint $t) {
            $t->unsignedInteger('cost_micros')->default(0)->after('cost_cents');
            $t->unsignedInteger('sale_micros')->default(0)->after('sale_cents');
        });

        // Tenant-specific overrides
        Schema::table('tenant_service_prices', function (Blueprint $t) {
            $t->unsignedInteger('sale_micros')->default(0)->after('sale_cents');
        });

        // Snapshot do preço no momento do envio (vai pra cobrança).
        // CRÍTICO: garante que o histórico financeiro preserva a precisão real.
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->unsignedInteger('cost_micros')->default(0)->after('cost_cents');
            $t->unsignedInteger('sale_micros')->default(0)->after('sale_cents');
        });

        // Backfill: 1 cent = 1000 micros. Preserva valores existentes sem precisão.
        DB::table('service_prices')->update([
            'cost_micros' => DB::raw('cost_cents * 1000'),
            'sale_micros' => DB::raw('sale_cents * 1000'),
        ]);
        DB::table('tenant_service_prices')->update([
            'sale_micros' => DB::raw('sale_cents * 1000'),
        ]);
        // Dispatches existentes ficam com micros=0; PricingService faz fallback para cents.
    }

    public function down(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['cost_micros', 'sale_micros']);
        });
        Schema::table('tenant_service_prices', function (Blueprint $t) {
            $t->dropColumn('sale_micros');
        });
        Schema::table('service_prices', function (Blueprint $t) {
            $t->dropColumn(['cost_micros', 'sale_micros']);
        });
    }
};
