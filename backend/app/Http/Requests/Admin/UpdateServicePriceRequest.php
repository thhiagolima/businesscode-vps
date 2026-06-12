<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServicePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cost_cents'  => ['required', 'integer', 'min:0', 'max:10000000'],
            'sale_cents'  => ['required', 'integer', 'min:0', 'max:10000000'],
            // Optional: when present, takes precedence over cents (preserves sub-cent precision).
            // 1 micro = R$ 0,00001 — necessary to represent supplier costs like R$ 0,0605 SMS without rounding.
            'cost_micros' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000000'],
            'sale_micros' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000000'],
            'reason'      => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
