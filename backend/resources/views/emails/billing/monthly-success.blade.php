@component('mail::message')
# Cobrança mensal realizada

Olá {{ $tenant->name }},

Sua cobrança mensal foi processada com sucesso.

- **Valor cobrado:** R$ {{ number_format($amountCents / 100, 2, ',', '.') }}
- **Saldo após cobrança:** R$ {{ number_format($newBalanceCents / 100, 2, ',', '.') }}

@component('mail::button', ['url' => $statementUrl])
Ver extrato completo
@endcomponent

Obrigado por confiar na {{ config('business.brand_name', config('app.name')) }}.

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
