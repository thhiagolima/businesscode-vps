<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGPD art. 8: consent must be informed, unambiguous and recorded with
 * version/timestamp so we can prove it if asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lgpd_consented_at')->nullable()->after('remember_token');
            $table->string('lgpd_consent_version', 20)->nullable()->after('lgpd_consented_at');
            $table->string('lgpd_consent_ip', 45)->nullable()->after('lgpd_consent_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lgpd_consented_at', 'lgpd_consent_version', 'lgpd_consent_ip']);
        });
    }
};
