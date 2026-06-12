<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_service_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('service', 50);
            $t->unsignedInteger('sale_cents');
            $t->string('reason', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'service'], 'tsp_tenant_service_unique');
            $t->index(['tenant_id'], 'tsp_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_service_prices');
    }
};
