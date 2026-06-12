<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->timestamp('opened_at')->nullable()->after('voice_status');
            $t->timestamp('first_clicked_at')->nullable()->after('opened_at');
            $t->timestamp('bounced_at')->nullable()->after('first_clicked_at');
            // Infobip bounce kinds: HARD, SOFT, BLOCK, etc.
            $t->string('bounce_type', 32)->nullable()->after('bounced_at');
            $t->timestamp('complaint_at')->nullable()->after('bounce_type');
            $t->timestamp('unsubscribed_at')->nullable()->after('complaint_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn([
                'opened_at', 'first_clicked_at', 'bounced_at',
                'bounce_type', 'complaint_at', 'unsubscribed_at',
            ]);
        });
    }
};
