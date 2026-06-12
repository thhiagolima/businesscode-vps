<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B11 — Ajuste manual de saldo colide com bt_idempotency_unique.
 *
 * O UNIQUE (tenant_id, type, reference_type, reference_id) foi criado para
 * idempotência forte de recharge/reserve/release. Para manual_adjustment,
 * o reference_id era o admin_user_id, então o mesmo admin não conseguia
 * fazer 2 ajustes para o mesmo tenant — erro 1062 ao adicionar saldo.
 *
 * Fix: coluna dedicada para "quem executou", liberando reference_id pra
 * receber um identificador único por transação (timestamp ms + jitter)
 * — preserva a UNIQUE constraint para recharge/reserve/release intacta.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->foreignId('executed_by_user_id')
                ->nullable()
                ->after('reference_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('executed_by_user_id');
        });
    }
};
