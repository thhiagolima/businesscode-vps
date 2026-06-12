<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_sender_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('domain', 253);
            $t->string('infobip_domain_id', 64)->nullable();
            $t->enum('status', ['pending', 'verifying', 'active', 'failed'])->default('pending');

            // DNS records the tenant must add (filled after register)
            $t->string('dkim_selector', 64)->nullable();
            $t->text('dkim_value')->nullable();                 // TXT value (long, multi-line)
            $t->text('spf_value')->nullable();                  // TXT value
            $t->string('return_path_value', 253)->nullable();   // CNAME target

            // Per-record verification flags (mirrored from Infobip GET response)
            $t->boolean('dkim_verified')->default(false);
            $t->boolean('spf_verified')->default(false);
            $t->boolean('return_path_verified')->default(false);

            $t->boolean('tracking_opens')->default(false);
            $t->boolean('tracking_clicks')->default(false);

            $t->timestamp('last_verified_at')->nullable();
            $t->string('last_verification_error', 500)->nullable();
            $t->unsignedInteger('verification_attempts')->default(0);

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['tenant_id', 'domain'], 'esd_tenant_domain_unique');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sender_domains');
    }
};
