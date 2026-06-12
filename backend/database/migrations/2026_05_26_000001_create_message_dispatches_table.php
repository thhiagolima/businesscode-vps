<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_dispatches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('channel', ['sms', 'voice', 'email']);
            $t->enum('source', ['api', 'transactional', 'internal'])->default('api');
            $t->string('to', 255);
            $t->string('from', 255)->nullable();
            $t->string('subject', 255)->nullable();
            $t->text('content');
            $t->string('audio_url', 500)->nullable();
            $t->string('provider', 50);
            $t->string('external_message_id', 120)->nullable();
            $t->enum('status', [
                'queued', 'sending', 'sent', 'delivered',
                'failed', 'rejected_opt_out', 'rejected_quiet_hours',
            ])->default('queued');
            $t->unsignedInteger('credits_unit')->default(0);
            $t->unsignedInteger('credits_charged')->default(0);
            $t->string('idempotency_key', 64)->nullable();
            $t->char('idempotency_payload_hash', 64)->nullable();
            $t->string('unsubscribe_token', 64)->nullable();
            $t->timestamp('unsubscribe_consumed_at')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->string('error_message', 500)->nullable();
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'idempotency_key'], 'msg_disp_tenant_idem_unique');
            $t->unique('unsubscribe_token', 'msg_disp_unsub_unique');
            $t->index(['tenant_id', 'channel', 'created_at'], 'msg_disp_tenant_channel_idx');
            $t->index('external_message_id', 'msg_disp_external_idx');
            $t->index(['status', 'scheduled_for'], 'msg_disp_status_sched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_dispatches');
    }
};
