<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('channel', 20); // sms, voice, email, whatsapp
            $table->enum('status', ['disabled', 'enabled', 'pending_setup'])->default('disabled');
            $table->json('config')->nullable(); // channel-specific config
            $table->timestamps();

            $table->unique(['tenant_id', 'channel']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Enable SMS by default for all existing tenants
        $tenants = \DB::table('tenants')->pluck('id');
        foreach ($tenants as $tenantId) {
            \DB::table('tenant_channels')->insert([
                'tenant_id' => $tenantId,
                'channel' => 'sms',
                'status' => 'enabled',
                'config' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_channels');
    }
};
