<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('generation_id')->unique();
            $table->string('service'); // sms / voice / email / sms_analysis / ...
            $table->string('prompt_version')->nullable();
            $table->json('input_payload');
            $table->json('output')->nullable();
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->string('model')->default('grok-beta');
            $table->enum('status', ['pending','completed','failed'])->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'service', 'status']);
            $table->index(['tenant_id', 'generation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};

