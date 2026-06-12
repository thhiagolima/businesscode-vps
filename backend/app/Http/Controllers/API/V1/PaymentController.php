<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBoletoPaymentRequest;
use App\Http\Requests\CreatePixPaymentRequest;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private MercadoPagoService $mpService,
        private BillingService $billing,
    ) {}

    /**
     * Frontend (Saldo.vue / CheckoutForm) now sends `credits_amount` as CENTS.
     * Older code paths expected a credit count multiplied by services.credits.unit_price.
     * To keep both clients working we treat the input as cents directly: 1 cent = R$0,01.
     */
    private function centsFromRequest($request): int
    {
        return (int) ($request->input('amount_cents') ?? $request->input('credits_amount') ?? 0);
    }

    public function pix(CreatePixPaymentRequest $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $cents = $this->centsFromRequest($request);
        $isCredits = $cents > 0;
        $billingCycle = $request->input('billing_cycle', 'monthly');
        $companyName = config('business.company_name', 'BusinessCode');

        if ($isCredits) {
            $amount = round($cents / 100, 2);
            $brlLabel = number_format($amount, 2, ',', '.');
            $description = "Recarga de saldo R$ {$brlLabel} - {$companyName}";
        } else {
            $plan = Plan::findOrFail($request->plan_id);
            $amount = $plan->priceFor($billingCycle);
            $cycleLabel = $billingCycle === 'annual' ? 'anual' : 'mensal';
            $description = "Assinatura {$plan->name} ({$cycleLabel}) - {$companyName}";
        }

        try {
            $result = $this->mpService->createPixPayment($amount, $description, $request->payer_email);

            $subscription = null;
            if (!$isCredits) {
                $plan = Plan::findOrFail($request->plan_id);
                $periodEnd = $billingCycle === 'annual' ? now()->addYear() : now()->addMonth();
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'status' => 'pending',
                    'payment_method' => 'pix',
                    'current_period_start' => now(),
                    'current_period_end' => $periodEnd,
                    'price' => $amount,
                ]);
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'mp_payment_id' => $result['mp_payment_id'],
                'type' => $isCredits ? 'credit_purchase' : 'subscription',
                'status' => $result['status'] === 'approved' ? 'approved' : 'pending',
                'payment_method' => 'pix',
                'amount' => $amount,
                'credits_purchased' => $isCredits ? $cents : null,
                'pix_qr_code' => $result['qr_code'],
                'pix_qr_code_base64' => $result['qr_code_base64'],
                'pix_expiration' => $result['expiration'],
            ]);

            // Rare but real: MP can return PIX already `approved` at creation. The webhook
            // would then skip crediting (old == new == approved), so the balance would never
            // be applied. Credit synchronously; idempotent on (credit_purchase, payment_id),
            // so a later webhook won't double-credit.
            if ($isCredits && $payment->status === 'approved') {
                $this->billing->recharge(
                    $tenant->id,
                    $cents,
                    'credit_purchase',
                    $payment->id,
                    "Compra de saldo (payment #{$payment->id})"
                );
            }

            return ApiResponse::success([
                'payment_id' => $payment->id,
                'mp_payment_id' => $payment->mp_payment_id,
                'status' => $payment->status,
                'pix_qr_code' => $payment->pix_qr_code,
                'pix_qr_code_base64' => $payment->pix_qr_code_base64,
                'pix_expiration' => $payment->pix_expiration,
            ], 'PIX gerado com sucesso.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] PIX creation failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao gerar PIX. Tente novamente.', [], 500);
        }
    }

    public function boleto(CreateBoletoPaymentRequest $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $cents = $this->centsFromRequest($request);
        $isCredits = $cents > 0;
        $billingCycle = $request->input('billing_cycle', 'monthly');
        $companyName = config('business.company_name', 'BusinessCode');

        if ($isCredits) {
            $amount = round($cents / 100, 2);
            $brlLabel = number_format($amount, 2, ',', '.');
            $description = "Recarga de saldo R$ {$brlLabel} - {$companyName}";
        } else {
            $plan = Plan::findOrFail($request->plan_id);
            $amount = $plan->priceFor($billingCycle);
            $cycleLabel = $billingCycle === 'annual' ? 'anual' : 'mensal';
            $description = "Assinatura {$plan->name} ({$cycleLabel}) - {$companyName}";
        }

        try {
            $result = $this->mpService->createBoletoPayment($amount, $description, $request->payer_email, $request->payer_document);

            $subscription = null;
            if (!$isCredits) {
                $plan = Plan::findOrFail($request->plan_id);
                $periodEnd = $billingCycle === 'annual' ? now()->addYear() : now()->addMonth();
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'status' => 'pending',
                    'payment_method' => 'boleto',
                    'current_period_start' => now(),
                    'current_period_end' => $periodEnd,
                    'price' => $amount,
                ]);
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'mp_payment_id' => $result['mp_payment_id'],
                'type' => $isCredits ? 'credit_purchase' : 'subscription',
                'status' => 'pending',
                'payment_method' => 'boleto',
                'amount' => $amount,
                'credits_purchased' => $isCredits ? $cents : null,
                'boleto_url' => $result['boleto_url'],
                'boleto_barcode' => $result['barcode'],
            ]);

            return ApiResponse::success([
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'boleto_url' => $payment->boleto_url,
                'boleto_barcode' => $payment->boleto_barcode,
            ], 'Boleto gerado com sucesso.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] Boleto creation failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao gerar boleto. Tente novamente.', [], 500);
        }
    }

    public function credits(PurchaseCreditsRequest $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $companyName = config('business.company_name', 'BusinessCode');
        $cents = $this->centsFromRequest($request);
        if ($cents <= 0) {
            return ApiResponse::error('Valor inválido.', [], 422);
        }
        $amount = round($cents / 100, 2);
        $brlLabel = number_format($amount, 2, ',', '.');
        $description = "Recarga de saldo R$ {$brlLabel} - {$companyName}";

        try {
            if ($request->payment_method === 'pix') {
                $result = $this->mpService->createPixPayment($amount, $description, $request->payer_email);
                $payment = Payment::create([
                    'tenant_id' => $tenant->id,
                    'mp_payment_id' => $result['mp_payment_id'],
                    'type' => 'credit_purchase',
                    'status' => $result['status'] === 'approved' ? 'approved' : 'pending',
                    'payment_method' => 'pix',
                    'amount' => $amount,
                    'credits_purchased' => $cents,
                    'pix_qr_code' => $result['qr_code'],
                    'pix_qr_code_base64' => $result['qr_code_base64'],
                    'pix_expiration' => $result['expiration'],
                ]);

                AuditLog::record('payment.pix_created', 'Payment', $payment->id, [
                    'amount_cents' => $cents,
                ], null, $tenant->id);

                // Credit immediately if MP approved the PIX at creation (webhook would
                // otherwise skip on old == new). Idempotent on (credit_purchase, payment_id).
                if ($payment->status === 'approved') {
                    $this->billing->recharge(
                        $tenant->id,
                        $cents,
                        'credit_purchase',
                        $payment->id,
                        "Compra de saldo (payment #{$payment->id})"
                    );
                }

                return ApiResponse::success([
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                    'pix_qr_code' => $payment->pix_qr_code,
                    'pix_qr_code_base64' => $payment->pix_qr_code_base64,
                    'pix_expiration' => $payment->pix_expiration,
                ], 'PIX gerado.', [], 201);
            }

            if ($request->payment_method === 'boleto') {
                $result = $this->mpService->createBoletoPayment($amount, $description, $request->payer_email, $request->payer_document);
                $payment = Payment::create([
                    'tenant_id' => $tenant->id,
                    'mp_payment_id' => $result['mp_payment_id'],
                    'type' => 'credit_purchase',
                    'status' => 'pending',
                    'payment_method' => 'boleto',
                    'amount' => $amount,
                    'credits_purchased' => $cents,
                    'boleto_url' => $result['boleto_url'],
                    'boleto_barcode' => $result['barcode'],
                ]);

                AuditLog::record('payment.boleto_created', 'Payment', $payment->id, [
                    'amount_cents' => $cents,
                ], null, $tenant->id);

                return ApiResponse::success([
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                    'boleto_url' => $payment->boleto_url,
                    'boleto_barcode' => $payment->boleto_barcode,
                ], 'Boleto gerado.', [], 201);
            }

            // credit_card — one-time payment. Single atomic flow:
            // 1) create local Payment as 'pending'
            // 2) charge MP
            // 3) reconcile status + credit balance in one DB transaction
            $payment = DB::transaction(function () use ($tenant, $amount, $cents) {
                return Payment::create([
                    'tenant_id' => $tenant->id,
                    'mp_payment_id' => null,
                    'type' => 'credit_purchase',
                    'status' => 'pending',
                    'payment_method' => 'credit_card',
                    'amount' => $amount,
                    'credits_purchased' => $cents,
                ]);
            });

            try {
                $mpResult = (new \MercadoPago\Client\Payment\PaymentClient())->create([
                    'transaction_amount' => $amount,
                    'description' => $description,
                    'token' => $request->card_token,
                    'installments' => 1,
                    'external_reference' => 'payment_' . $payment->id,
                    'payer' => ['email' => $request->payer_email],
                ]);
            } catch (\Throwable $mpError) {
                $payment->update(['status' => 'rejected']);
                Log::error('[MercadoPago] credit purchase charge failed', [
                    'tenant_id' => $tenant->id,
                    'payment_id' => $payment->id,
                    'error' => $mpError->getMessage(),
                ]);
                AuditLog::record('payment.rejected', 'Payment', $payment->id, [
                    'amount_cents' => $cents,
                    'error' => mb_substr($mpError->getMessage(), 0, 200),
                ], null, $tenant->id);
                return ApiResponse::error('Erro ao processar pagamento. Tente novamente.', [], 500);
            }

            DB::transaction(function () use ($payment, $mpResult, $request, $tenant, $cents) {
                $payment->update([
                    'mp_payment_id' => (string) $mpResult->id,
                    'status' => $mpResult->status === 'approved' ? 'approved' : 'pending',
                    'paid_at' => $mpResult->status === 'approved' ? now() : null,
                ]);

                if ($payment->status === 'approved') {
                    // Credit balance via BillingService (writes balance_cents + audit row).
                    $this->billing->recharge(
                        $tenant->id,
                        $cents,
                        'credit_purchase',
                        $payment->id,
                        "Compra de saldo (payment #{$payment->id})"
                    );

                    // Optional: save card for future monthly debit of overage.
                    // Defensive — never let MP errors here roll back the credit purchase.
                    if ((bool) $request->input('save_card_for_billing', false)) {
                        try {
                            $locked = \App\Models\Tenant::find($tenant->id);
                            $customerId = $locked->mp_customer_id
                                ?: $this->mpService->findOrCreateCustomer($request->payer_email);
                            $cardId = $customerId
                                ? $this->mpService->saveCardOnCustomer($customerId, $request->card_token)
                                : null;

                            if ($customerId && $cardId) {
                                $locked->update([
                                    'mp_customer_id'     => $customerId,
                                    'mp_default_card_id' => $cardId,
                                ]);
                            } else {
                                Log::warning('[MercadoPago] save_card_for_billing requested but MP did not return reusable card data', [
                                    'tenant_id'   => $locked->id,
                                    'customer_id' => $customerId,
                                    'card_id'     => $cardId,
                                ]);
                            }
                        } catch (\Throwable $cardError) {
                            Log::warning('[MercadoPago] save_card_for_billing failed (ignored)', [
                                'tenant_id' => $tenant->id,
                                'error'     => $cardError->getMessage(),
                            ]);
                        }
                    }
                }
            });

            $finalStatus = $payment->fresh()->status;
            AuditLog::record(
                $finalStatus === 'approved' ? 'payment.approved' : 'payment.pending',
                'Payment', $payment->id,
                ['amount_cents' => $cents, 'method' => 'credit_card'],
                null, $tenant->id
            );

            return ApiResponse::success([
                'payment_id' => $payment->id,
                'status' => $finalStatus,
            ], 'Pagamento processado.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] credit purchase failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao processar pagamento. Tente novamente.', [], 500);
        }
    }

    public function index()
    {
        $tenant = request()->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $payments = Payment::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return ApiResponse::paginated($payments);
    }

    public function status(int $id)
    {
        $tenant = request()->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);
        $payment = Payment::where('id', $id)->where('tenant_id', $tenant->id)->firstOrFail();

        return ApiResponse::success([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at,
        ]);
    }
}
