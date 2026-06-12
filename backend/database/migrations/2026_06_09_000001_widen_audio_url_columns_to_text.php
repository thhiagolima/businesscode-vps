<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * URLs pré-assinadas (Storage::temporaryUrl — S3 signature / Azure SAS token)
     * facilmente ultrapassam 255 e até 500 caracteres, causando
     * SQLSTATE[22001] "Data too long for column 'audio_url'".
     * Ampliamos para TEXT em todas as tabelas que guardam essa URL.
     */
    public function up(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->text('audio_url')->nullable()->change();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('audio_url')->nullable()->change();
        });

        Schema::table('message_dispatches', function (Blueprint $table) {
            $table->text('audio_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audio_generations', function (Blueprint $table) {
            $table->string('audio_url')->nullable()->change();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('audio_url')->nullable()->change();
        });

        Schema::table('message_dispatches', function (Blueprint $table) {
            $table->string('audio_url', 500)->nullable()->change();
        });
    }
};
