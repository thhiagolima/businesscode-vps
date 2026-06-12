<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price_monthly', 10, 2);
            $table->unsignedInteger('credits_included');
            $table->unsignedInteger('max_contacts');
            $table->unsignedInteger('max_campaigns');
            $table->decimal('overage_rate_sms', 10, 4);
            $table->decimal('overage_rate_voice', 10, 4);
            $table->decimal('overage_rate_email', 10, 4);
            $table->decimal('overage_rate_ai', 10, 4);
            $table->json('features')->nullable();
            $table->timestamps();
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
