<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // The frontend may send the recharge amount as either `credits_amount` (legacy field
        // name, now expressed in CENTS) or the explicit `amount_cents`. Minimum is in cents:
        // services.credits.min_purchase defaults to 100 (R$ 1,00). Cap at R$ 100.000,00.
        $min = (int) config('services.credits.min_purchase', 100);
        return [
            'credits_amount'        => ['required_without:amount_cents', 'nullable', 'integer', 'min:' . $min, 'max:10000000'],
            'amount_cents'          => ['required_without:credits_amount', 'nullable', 'integer', 'min:' . $min, 'max:10000000'],
            'payment_method'        => ['required', 'in:credit_card,pix,boleto'],
            'card_token'            => ['required_if:payment_method,credit_card', 'nullable', 'string'],
            'payer_email'           => ['required', 'email', 'max:255'],
            'payer_document'        => ['required_if:payment_method,boleto', 'nullable', 'string', 'min:11', 'max:18'],
            'save_card_for_billing' => ['nullable', 'boolean'],
        ];
    }
}
