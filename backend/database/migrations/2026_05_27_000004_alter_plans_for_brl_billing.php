<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('plans', function (Blueprint $t) {
            $t->bigInteger('included_balance_cents')->default(0);
            $t->json('sale_cents_overrides')->nullable();
        });

        // Data migration: credits_included * price_cents → included_balance_cents
        DB::statement(
            "UPDATE plans SET included_balance_cents = COALESCE(credits_included, 0) * ?",
            [$priceCents]
        );

        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn([
                'credits_included',
                'overage_rate_sms',
                'overage_rate_voice',
                'overage_rate_email',
                'overage_rate_ai',
                'credits_per_sms_override',
                'credits_per_voice_override',
                'credits_per_email_override',
            ]);
        });
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('plans', function (Blueprint $t) {
            $t->integer('credits_included')->default(0);
            $t->decimal('overage_rate_sms', 8, 4)->nullable();
            $t->decimal('overage_rate_voice', 8, 4)->nullable();
            $t->decimal('overage_rate_email', 8, 4)->nullable();
            $t->decimal('overage_rate_ai', 8, 4)->nullable();
            $t->unsignedInteger('credits_per_sms_override')->nullable();
            $t->unsignedInteger('credits_per_voice_override')->nullable();
            $t->unsignedInteger('credits_per_email_override')->nullable();
        });

        DB::statement(
            "UPDATE plans SET credits_included = FLOOR(included_balance_cents / ?)",
            [$priceCents]
        );

        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['included_balance_cents', 'sale_cents_overrides']);
        });
    }
};
