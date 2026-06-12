<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P0-04: persist a pricing snapshot per campaign so the cost cannot be silently
     * changed by an admin after the batch starts. Replaces the JSON `settings` keys
     * with indexable columns for reports / audits.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->unsignedInteger('unit_cents_at_dispatch')->nullable()->after('estimated_contacts');
            $t->unsignedInteger('reserved_cents')->nullable()->after('unit_cents_at_dispatch');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropColumn(['unit_cents_at_dispatch', 'reserved_cents']);
        });
    }
};
