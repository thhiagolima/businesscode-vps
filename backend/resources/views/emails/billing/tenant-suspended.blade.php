@component('mail::message')
# Sua conta foi suspensa

Olá {{ $tenant->name }},

Após o período de 7 dias de tolerância, sua conta foi **suspensa** por inadimplência. Os envios e campanhas estão bloqueados temporariamente.

Para reativar sua conta, atualize seu cartão e regularize o saldo pendente.

@component('mail::button', ['url' => $statementUrl, 'color' => 'error'])
Reativar minha conta
@endcomponent

Caso já tenha resolvido, ignore este aviso — a reativação é automática em alguns minutos.

Atenciosamente,<br>
{{ config('business.brand_name', config('app.name')) }}
@endcomponent
