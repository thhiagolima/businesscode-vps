<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp em todos os planos com cobrança separada do saldo:
 *  - whatsapp_setup_fee_cents: cobrado one-time no onboarding WABA
 *  - whatsapp_billed_separately: se true, mensagens não consomem saldo do plano
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('whatsapp_setup_fee_cents')->default(0)->after('included_balance_cents');
            $t->boolean('whatsapp_billed_separately')->default(true)->after('whatsapp_setup_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['whatsapp_setup_fee_cents', 'whatsapp_billed_separately']);
        });
    }
};
