<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('mp_subscription_id')->nullable()->index();
            $table->string('mp_payer_id')->nullable();
            $table->enum('status', ['pending', 'authorized', 'active', 'paused', 'cancelled'])->default('pending')->index();
            $table->enum('payment_method', ['credit_card', 'pix', 'boleto'])->default('credit_card');
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
