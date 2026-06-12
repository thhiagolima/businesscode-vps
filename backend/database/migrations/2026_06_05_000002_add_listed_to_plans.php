<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('listed')->default(true)->after('slug');
        });

        // Plano de parceria criado direto no banco não deve aparecer na landing.
        DB::table('plans')->where('slug', 'custom-pedro')->update(['listed' => false]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('listed');
        });
    }
};
