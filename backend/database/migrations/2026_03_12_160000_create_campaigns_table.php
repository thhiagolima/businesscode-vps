<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['sms', 'voice', 'email']);
            $table->enum('status', ['draft', 'processing', 'running', 'completed', 'failed', 'scheduled'])->default('draft')->index();
            $table->text('content')->nullable();
            $table->string('subject')->nullable();
            $table->string('audio_url')->nullable();
            $table->unsignedBigInteger('contact_list_id')->nullable();
            $table->boolean('strategy_locked')->default(false);
            $table->json('settings')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('estimated_contacts')->default(0);
            $table->string('warning_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

