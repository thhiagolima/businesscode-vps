<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->timestamp('answered_at')->nullable()->after('failed_at');
            $t->timestamp('ended_at')->nullable()->after('answered_at');
            $t->unsignedInteger('call_duration_seconds')->nullable()->after('ended_at');
            // Granular voice-specific status from Infobip:
            // DELIVERED_TO_HANDSET, ANSWERED, NO_ANSWER, BUSY, FAILED, REJECTED, EXPIRED, etc.
            $t->string('voice_status', 64)->nullable()->after('call_duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['answered_at', 'ended_at', 'call_duration_seconds', 'voice_status']);
        });
    }
};
