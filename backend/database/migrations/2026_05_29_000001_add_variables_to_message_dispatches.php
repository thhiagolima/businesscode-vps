<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            // Template variables sent by the caller (e.g. {"primeiro_nome":"Pedro",
            // "dias":7}). Substituted into subject/content by the controllers
            // before dispatch; persisted here for audit and reporting.
            // NULL when the caller sent no `variables` field.
            $t->json('variables')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn('variables');
        });
    }
};
