<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnel_executions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('funnel_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('current_node_id');
            $table->enum('status', ['running', 'waiting', 'completed', 'cancelled'])->default('running');
            $table->timestamp('wait_until')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->index(['status', 'wait_until']);
            $table->index(['contact_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('funnel_executions'); }
};
