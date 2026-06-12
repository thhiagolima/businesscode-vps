<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_dispatches', function (Blueprint $t) {
            $t->string('unsubscribe_token', 64)->nullable()->unique('cd_unsub_token_unique');
            $t->timestamp('unsubscribe_consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_dispatches', function (Blueprint $t) {
            $t->dropUnique('cd_unsub_token_unique');
            $t->dropColumn(['unsubscribe_token', 'unsubscribe_consumed_at']);
        });
    }
};
