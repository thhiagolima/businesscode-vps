<?php

namespace Tests\Feature\Billing;

use App\Models\BalanceTransaction;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_approved_credit_purchase_credits_balance_once(): void
    {
        config()->set('services.mercadopago.access_token', 'APP_USR-test');

        $tenant = Tenant::create([
            'name' => 'Tenant',
            'slug' => 'tenant-'.uniqid(),
            'status' => 'active',
            'balance_cents' => 0,
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'mp_payment_id' => 'mp_123',
            'type' => 'credit_purchase',
            'status' => 'pending',
            'payment_method' => 'pix',
            'amount' => 50.00,
            'credits_purchased' => 5000,
        ]);

        Http::fake([
            'api.mercadopago.com/v1/payments/mp_123' => Http::response([
                'id' => 'mp_123',
                'status' => 'approved',
                'transaction_details' => [
                    'net_received_amount' => 49.10,
                ],
            ], 200),
        ]);

        $service = app(MercadoPagoService::class);
        $service->reconcilePayment($payment);
        $service->reconcilePayment($payment->fresh());

        $this->assertSame('approved', $payment->fresh()->status);
        $this->assertSame(5000, (int) $tenant->fresh()->balance_cents);
        $this->assertSame(1, BalanceTransaction::where('tenant_id', $tenant->id)
            ->where('type', 'recharge')
            ->where('reference_type', 'credit_purchase')
            ->where('reference_id', $payment->id)
            ->count());
    }

    public function test_reconcile_command_processes_pending_payments_without_frontend_polling(): void
    {
        config()->set('services.mercadopago.access_token', 'APP_USR-test');

        $tenant = Tenant::create([
            'name' => 'Tenant',
            'slug' => 'tenant-'.uniqid(),
            'status' => 'active',
            'balance_cents' => 0,
        ]);

        Payment::create([
            'tenant_id' => $tenant->id,
            'mp_payment_id' => 'mp_456',
            'type' => 'credit_purchase',
            'status' => 'pending',
            'payment_method' => 'boleto',
            'amount' => 25.00,
            'credits_purchased' => 2500,
        ]);

        Http::fake([
            'api.mercadopago.com/v1/payments/mp_456' => Http::response([
                'id' => 'mp_456',
                'status' => 'approved',
                'transaction_details' => [
                    'net_received_amount' => 24.70,
                ],
            ], 200),
        ]);

        $this->artisan('billing:reconcile-mp-payments')
            ->expectsOutput('Reconciled 1 of 1 pending Mercado Pago payments.')
            ->assertExitCode(0);

        $this->assertSame(2500, (int) $tenant->fresh()->balance_cents);
    }
}
