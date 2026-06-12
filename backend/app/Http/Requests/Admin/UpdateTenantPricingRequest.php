<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service'    => ['required', 'in:sms,voice,email,ai_generation,audio_tts'],
            'sale_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'reason'     => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
