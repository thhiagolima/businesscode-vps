<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBoletoPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        // `credits_amount` is interpreted as CENTS by the controller (legacy field name).
        $min = (int) config('services.credits.min_purchase', 100);
        return [
            'plan_id'        => ['required_without_all:credits_amount,amount_cents', 'nullable', 'exists:plans,id'],
            'credits_amount' => ['required_without_all:plan_id,amount_cents', 'nullable', 'integer', 'min:' . $min, 'max:10000000'],
            'amount_cents'   => ['nullable', 'integer', 'min:' . $min, 'max:10000000'],
            'payer_email'    => ['required', 'email', 'max:255'],
            'payer_document' => ['required', 'string', 'min:11', 'max:18'],
            'billing_cycle'  => ['nullable', 'in:monthly,annual'],
        ];
    }
}
