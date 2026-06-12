<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            // Display name shown in recipient's inbox (e.g. "Empresa Parceria"
            // instead of just marketing@empresa.com.br). Email-only field; NULL
            // on SMS/voice dispatches.
            $t->string('from_name', 100)->nullable()->after('from');

            // Reply-To header so recipient hitting "Reply" goes to a different
            // address than the From (e.g. send from no-reply@x but route replies
            // to support@x). Email-only; NULL on SMS/voice.
            $t->string('reply_to', 255)->nullable()->after('from_name');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['from_name', 'reply_to']);
        });
    }
};
