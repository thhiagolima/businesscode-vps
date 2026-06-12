<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->char('monthly_cycle_key', 7)->nullable()->after('reference_id'); // 'YYYYMM' or NULL for non-monthly
            $t->unique(['tenant_id', 'reference_type', 'monthly_cycle_key'], 'bt_monthly_unique');
        });
    }

    public function down(): void
    {
        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropUnique('bt_monthly_unique');
            $t->dropColumn('monthly_cycle_key');
        });
    }
};
