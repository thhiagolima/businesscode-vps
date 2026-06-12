<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elevenlabs_voices', function (Blueprint $table) {
            $table->id();
            $table->string('voice_id')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('gender')->nullable();
            $table->string('accent')->nullable();
            $table->string('language')->nullable();
            $table->string('preview_url')->nullable();
            $table->json('labels')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elevenlabs_voices');
    }
};

