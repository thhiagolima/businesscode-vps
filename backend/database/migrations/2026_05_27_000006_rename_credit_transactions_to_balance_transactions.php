<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::rename('credit_transactions', 'balance_transactions');

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->bigInteger('amount_cents')->default(0);
            $t->bigInteger('balance_after_cents')->default(0);
        });

        DB::statement(
            "UPDATE balance_transactions SET amount_cents = amount * ?, balance_after_cents = balance_after * ?",
            [$priceCents, $priceCents]
        );

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropColumn(['amount', 'balance_after']);
        });

        // Extend ENUM type — MySQL requires MODIFY COLUMN with full new ENUM list
        DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN type ENUM('debit','credit','reserve','release','recharge','monthly_charge','manual_adjustment') NOT NULL");
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN type ENUM('debit','credit','reserve','release') NOT NULL");

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->integer('amount')->default(0);
            $t->integer('balance_after')->default(0);
        });

        DB::statement(
            "UPDATE balance_transactions SET amount = FLOOR(amount_cents / ?), balance_after = FLOOR(balance_after_cents / ?)",
            [$priceCents, $priceCents]
        );

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropColumn(['amount_cents', 'balance_after_cents']);
        });

        Schema::rename('balance_transactions', 'credit_transactions');
    }
};
