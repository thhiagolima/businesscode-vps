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
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('service'); // sms / voice / email / sms_analysis / ...
            $table->string('version')->default('v1');
            $table->text('system_prompt');
            $table->text('user_template');
            $table->string('model')->default('grok-beta');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['service', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};

