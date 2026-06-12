<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Express re-audit follow-up: AuditLogController permite filtros por
 * action + intervalo de datas escopados por tenant. Os índices existentes
 * cobrem (tenant_id, created_at) e (user_id, action) mas não a query mais
 * comum da UI (action prefix + tenant). Esse composto resolve o cenário,
 * fechando a recomendação RT de "índice (tenant_id, action, created_at)
 * para mitigar DoS via LIKE não-indexado".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->index(['tenant_id', 'action', 'created_at'], 'audit_logs_tenant_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropIndex('audit_logs_tenant_action_created_idx');
        });
    }
};
