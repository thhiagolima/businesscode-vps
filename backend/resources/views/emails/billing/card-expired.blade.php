@component('mail::message')
# Seu cartão expirou ou foi recusado

Olá {{ $tenant->name }},

O cartão cadastrado para a cobrança mensal foi recusado pela operadora — provavelmente está **expirado** ou bloqueado.

Para evitar a suspensão do serviço, atualize seu cartão de crédito o quanto antes.

@component('mail::button', ['url' => $statementUrl])
Atualizar cartão agora
@endcomponent

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
