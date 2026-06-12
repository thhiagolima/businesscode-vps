<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('tenants', function (Blueprint $t) {
            $t->bigInteger('balance_cents')->default(0);
            $t->bigInteger('credit_limit_cents')->default(0);
            $t->enum('billing_status', ['active','grace','suspended','blocked'])->default('active');
            $t->unsignedTinyInteger('billing_cycle_day')->nullable();
            $t->timestamp('last_billing_at')->nullable();
            $t->timestamp('overdue_since')->nullable();
            $t->unsignedTinyInteger('overdue_attempts')->default(0);
            $t->string('mp_customer_id', 64)->nullable();
            $t->string('mp_default_card_id', 64)->nullable();
        });

        // Data migration: credits_balance * price_cents → balance_cents
        DB::statement(
            "UPDATE tenants SET balance_cents = COALESCE(credits_balance, 0) * ?",
            [$priceCents]
        );

        // billing_cycle_day = LEAST(DAY(created_at), 28)
        DB::statement("UPDATE tenants SET billing_cycle_day = LEAST(DAY(created_at), 28)");

        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('credits_balance');
        });
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('tenants', function (Blueprint $t) {
            $t->integer('credits_balance')->default(0);
        });

        DB::statement(
            "UPDATE tenants SET credits_balance = FLOOR(balance_cents / ?)",
            [$priceCents]
        );

        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn([
                'balance_cents','credit_limit_cents','billing_status',
                'billing_cycle_day','last_billing_at','overdue_since','overdue_attempts',
                'mp_customer_id','mp_default_card_id',
            ]);
        });
    }
};
