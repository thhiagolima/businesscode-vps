<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('voice_id'); // ElevenLabs voice id (string externa)
            $table->string('voice_name');
            $table->text('script');
            $table->string('audio_path')->nullable();
            $table->string('audio_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('characters_used')->nullable();
            $table->decimal('cost_price', 10, 4)->default(0);
            $table->decimal('sale_price', 10, 4)->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_generations');
    }
};
