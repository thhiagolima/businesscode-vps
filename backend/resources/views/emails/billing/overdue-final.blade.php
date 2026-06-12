@component('mail::message')
# Último aviso antes da suspensão

Olá {{ $tenant->name }},

Esta é a **última tentativa** de cobrança automática do mês. Seu cartão continua sendo recusado.

- **Valor pendente:** R$ {{ number_format($amountCents / 100, 2, ',', '.') }}
- **Dias em atraso:** {{ $dayOfGrace }}

Se nada for feito até amanhã, sua conta será **suspensa** e os envios serão bloqueados.

@component('mail::button', ['url' => $statementUrl, 'color' => 'error'])
Evitar suspensão agora
@endcomponent

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
