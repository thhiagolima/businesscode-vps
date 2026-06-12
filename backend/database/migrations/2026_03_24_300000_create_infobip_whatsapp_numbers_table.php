<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('infobip_whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('sender')->unique();       // Infobip sender ID
            $table->string('number');                  // Phone number display
            $table->string('display_name')->nullable(); // Business name
            $table->unsignedBigInteger('tenant_id')->nullable(); // NULL = disponível
            $table->string('status')->default('active'); // active, inactive
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infobip_whatsapp_numbers');
    }
};
