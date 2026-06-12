<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mp_payment_id must be nullable: the local Payment row is now created as
 * 'pending' before charging Mercado Pago so we can reconcile on failure.
 * The unique index stays so only successful charges (with id) are unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('mp_payment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('mp_payment_id')->nullable(false)->change();
        });
    }
};
