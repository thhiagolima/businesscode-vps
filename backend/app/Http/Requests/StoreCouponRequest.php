<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $couponId = $this->route('id');
        return [
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code' . ($couponId ? ',' . $couponId : '')],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0.01', function ($attribute, $value, $fail) {
                if ($this->input('discount_type') === 'percentage' && $value > 100) {
                    $fail('O desconto percentual não pode exceder 100%.');
                }
                if ($this->input('discount_type') === 'fixed' && $value > 99999) {
                    $fail('O valor máximo de desconto fixo é R$99.999.');
                }
            }],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
