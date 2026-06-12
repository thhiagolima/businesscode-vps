@component('mail::message')
# Cadastre um cartão para a cobrança mensal

Olá {{ $tenant->name }},

Sua cobrança mensal venceu, mas você ainda não cadastrou um cartão para débito automático.

- **Valor pendente:** R$ {{ number_format($amountCents / 100, 2, ',', '.') }}

Cadastre um cartão ou recarregue seu saldo manualmente para manter o serviço ativo.

@component('mail::button', ['url' => $statementUrl])
Cadastrar cartão / recarregar
@endcomponent

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
