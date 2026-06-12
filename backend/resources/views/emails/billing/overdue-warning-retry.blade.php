@component('mail::message')
# Nova tentativa de cobrança falhou

Olá {{ $tenant->name }},

Tentamos novamente cobrar seu cartão e a operação foi recusada.

- **Valor pendente:** R$ {{ number_format($amountCents / 100, 2, ',', '.') }}
- **Dia da tolerância:** {{ $dayOfGrace }} de 7

Para evitar a suspensão do serviço, atualize seu cartão ou recarregue seu saldo o quanto antes.

@component('mail::button', ['url' => $statementUrl])
Resolver pendência agora
@endcomponent

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
