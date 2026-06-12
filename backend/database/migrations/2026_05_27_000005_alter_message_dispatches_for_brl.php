<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);
        // Approximate cost as ~half of sale for legacy rows; new dispatches snapshot real values
        $costApprox = max(1, (int) floor($priceCents / 2));

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->unsignedInteger('cost_cents')->default(0);
            $t->unsignedInteger('sale_cents')->default(0);
            $t->unsignedInteger('charged_cents')->default(0);
        });

        // Data migration
        DB::statement(
            "UPDATE message_dispatches SET cost_cents = COALESCE(credits_unit, 0) * ?, sale_cents = COALESCE(credits_unit, 0) * ?, charged_cents = COALESCE(credits_charged, 0) * ?",
            [$costApprox, $priceCents, $priceCents]
        );

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['credits_unit', 'credits_charged']);
        });
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->unsignedInteger('credits_unit')->default(0);
            $t->unsignedInteger('credits_charged')->default(0);
        });

        DB::statement(
            "UPDATE message_dispatches SET credits_unit = FLOOR(sale_cents / ?), credits_charged = FLOOR(charged_cents / ?)",
            [$priceCents, $priceCents]
        );

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['cost_cents', 'sale_cents', 'charged_cents']);
        });
    }
};
