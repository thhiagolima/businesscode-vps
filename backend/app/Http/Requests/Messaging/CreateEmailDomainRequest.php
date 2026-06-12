<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class CreateEmailDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => [
                'required', 'string', 'max:253',
                'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
            ],
            'tracking_opens'  => ['nullable', 'boolean'],
            'tracking_clicks' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'domain.regex' => 'O dominio informado e invalido. Use um formato como "marketing.empresa.com.br".',
        ];
    }

    protected function prepareForValidation(): void
    {
        $domain = (string) $this->input('domain', '');
        $this->merge([
            'domain' => mb_strtolower(trim($domain)),
        ]);
    }
}
