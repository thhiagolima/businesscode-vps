<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mp_payment_id')->unique();
            $table->enum('type', ['subscription', 'credit_purchase'])->default('subscription');
            $table->enum('status', ['pending', 'approved', 'rejected', 'refunded', 'cancelled'])->default('pending')->index();
            $table->enum('payment_method', ['credit_card', 'pix', 'boleto'])->default('credit_card');
            $table->decimal('amount', 10, 2);
            $table->decimal('net_amount', 10, 2)->nullable();
            $table->unsignedInteger('credits_purchased')->nullable();
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_qr_code_base64')->nullable();
            $table->timestamp('pix_expiration')->nullable();
            $table->string('boleto_url')->nullable();
            $table->string('boleto_barcode')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
