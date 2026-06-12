<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $t) {
            $t->id();
            $t->string('service', 50)->unique();
            $t->unsignedInteger('cost_cents')->default(0);
            $t->unsignedInteger('sale_cents')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
