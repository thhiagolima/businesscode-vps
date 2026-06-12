<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outbound_webhook_id')->constrained('outbound_webhooks')->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('event', 80);
            $t->json('request_body');
            $t->smallInteger('response_status')->nullable();    // 200-599 or null on connection error
            $t->text('response_body')->nullable();              // truncated to 2000 chars
            $t->integer('duration_ms')->nullable();
            $t->unsignedTinyInteger('attempt_number')->default(1);
            $t->string('error_message', 500)->nullable();
            $t->timestamp('fired_at')->useCurrent();

            $t->index(['outbound_webhook_id', 'fired_at']);
            $t->index(['tenant_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
