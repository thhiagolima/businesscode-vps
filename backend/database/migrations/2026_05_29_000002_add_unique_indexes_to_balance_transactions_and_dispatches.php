<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0R-01 + P0R-02 — UNIQUE constraints no banco para fechar double-credit MP
 * e disparo duplicado de campanha. Antes só havia defesa em app-layer
 * (BillingService idempotency check + WithoutOverlapping). Aqui adicionamos
 * a defesa em profundidade no DB.
 *
 * balance_transactions: bloqueia recharge/reserve/release duplicados para
 * o mesmo (tenant_id, type, reference_type, reference_id).
 *
 * campaign_dispatches: bloqueia 2 envios para o mesmo contato/telefone na
 * mesma campanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Antes de aplicar UNIQUE, remova eventuais duplicatas (proteção contra
        // dados legados criados antes da idempotência ser plugada).
        // Em prod, faça migração explícita; em dev/test o banco é recriado.

        Schema::table('balance_transactions', function (Blueprint $t) {
            // (tenant, type, ref_type, ref_id) é a chave de idempotência usada por
            // BillingService::reserve/release/recharge. UNIQUE composto.
            $t->unique(
                ['tenant_id', 'type', 'reference_type', 'reference_id'],
                'bt_idempotency_unique'
            );
        });

        Schema::table('campaign_dispatches', function (Blueprint $t) {
            // Caminho contact_list: chave é (campaign_id, contact_id).
            $t->unique(['campaign_id', 'contact_id'], 'cd_campaign_contact_unique');
            // Caminho adhoc_phones: chave é (campaign_id, phone). MySQL permite
            // múltiplas linhas com phone NULL no UNIQUE (não conta NULL como dup).
            $t->unique(['campaign_id', 'phone'],      'cd_campaign_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropUnique('bt_idempotency_unique');
        });
        Schema::table('campaign_dispatches', function (Blueprint $t) {
            $t->dropUnique('cd_campaign_contact_unique');
            $t->dropUnique('cd_campaign_phone_unique');
        });
    }
};
