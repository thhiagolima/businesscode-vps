<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_sender_domains', function (Blueprint $t) {
            // Drop the FK + composite unique first so we can change column nullability.
            $t->dropForeign(['tenant_id']);
            $t->dropUnique('esd_tenant_domain_unique');
        });

        Schema::table('email_sender_domains', function (Blueprint $t) {
            // NULL = domain in the admin pool, not yet assigned to a tenant.
            $t->unsignedBigInteger('tenant_id')->nullable()->change();

            // Infobip enforces global uniqueness of domain per account; reflect that.
            $t->unique('domain', 'esd_domain_unique');

            // If a tenant is deleted, the domain goes back to the pool — don't delete it.
            $t->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_sender_domains', function (Blueprint $t) {
            $t->dropForeign(['tenant_id']);
            $t->dropUnique('esd_domain_unique');
        });

        Schema::table('email_sender_domains', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $t->unique(['tenant_id', 'domain'], 'esd_tenant_domain_unique');
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }
};
