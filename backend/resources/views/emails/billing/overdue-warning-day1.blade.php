@component('mail::message')
# Não conseguimos cobrar seu cartão

Olá {{ $tenant->name }},

Tentamos realizar a cobrança mensal automaticamente, mas seu cartão não foi aprovado.

- **Valor pendente:** R$ {{ number_format($amountCents / 100, 2, ',', '.') }}

Vamos tentar novamente nos próximos dias. Para evitar interrupções no serviço, atualize seu cartão ou recarregue seu saldo manualmente.

@component('mail::button', ['url' => $statementUrl])
Atualizar cartão / recarregar saldo
@endcomponent

Se nada for feito em até **7 dias**, o serviço será suspenso.

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
