<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class CreateOptOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel'    => ['required', 'in:sms,voice,email,whatsapp,all'],
            'identifier' => ['required', 'string', 'max:255'],
            // Reason é opcional na UI; em chamadas via API transactional continua sendo recomendado.
            'reason'     => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('reason') || $this->input('reason') === null || $this->input('reason') === '') {
            $this->merge(['reason' => 'manual_ui']);
        }
    }
}
