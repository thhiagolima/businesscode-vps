<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('rate_limit_sms_per_min')->nullable()->after('overage_rate_ai');
            $t->unsignedInteger('rate_limit_voice_per_min')->nullable()->after('rate_limit_sms_per_min');
            $t->unsignedInteger('rate_limit_email_per_min')->nullable()->after('rate_limit_voice_per_min');
            $t->unsignedInteger('credits_per_sms_override')->nullable()->after('rate_limit_email_per_min');
            $t->unsignedInteger('credits_per_voice_override')->nullable()->after('credits_per_sms_override');
            $t->unsignedInteger('credits_per_email_override')->nullable()->after('credits_per_voice_override');
            $t->boolean('quiet_hours_enabled')->default(true)->after('credits_per_email_override');
            $t->time('quiet_hours_start')->default('22:00:00')->after('quiet_hours_enabled');
            $t->time('quiet_hours_end')->default('08:00:00')->after('quiet_hours_start');
            $t->string('quiet_hours_timezone', 50)->default('America/Sao_Paulo')->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn([
                'rate_limit_sms_per_min','rate_limit_voice_per_min','rate_limit_email_per_min',
                'credits_per_sms_override','credits_per_voice_override','credits_per_email_override',
                'quiet_hours_enabled','quiet_hours_start','quiet_hours_end','quiet_hours_timezone',
            ]);
        });
    }
};
