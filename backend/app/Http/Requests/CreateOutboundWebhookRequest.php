<?php
namespace App\Http\Requests;

use App\Services\Security\OutboundWebhookGuard;
use App\Services\Security\OutboundWebhookGuardException;
use App\Support\WebhookEvents;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOutboundWebhookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'url'       => [
                'required',
                'string',
                'url',
                'max:1024',
                function ($attribute, $value, $fail) {
                    if (! is_string($value) || ! str_starts_with(strtolower($value), 'https://')) {
                        $fail('A URL do webhook deve usar HTTPS.');
                        return;
                    }
                    try {
                        app(OutboundWebhookGuard::class)->assertSafeUrl($value);
                    } catch (OutboundWebhookGuardException $e) {
                        $fail('A URL aponta para um endereço bloqueado por segurança.');
                    }
                },
            ],
            'events'    => ['required', 'array', 'min:1'],
            'events.*'  => ['string', Rule::in(WebhookEvents::ALL)],
            'is_active' => ['nullable', 'boolean'],
            'secret'    => ['nullable', 'string', 'min:16', 'max:128'],
        ];
    }
}
