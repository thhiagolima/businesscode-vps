<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'exists:plans,id'],
            'card_token' => ['required', 'string'],
            'payer_email' => ['required', 'email', 'max:255'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'billing_cycle' => ['nullable', 'in:monthly,annual'],
            'save_card_for_billing' => ['nullable', 'boolean'],
        ];
    }
}
