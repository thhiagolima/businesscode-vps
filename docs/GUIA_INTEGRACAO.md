# Guia de Integração — BusinessCode API

> **Para quem é esse guia:** desenvolvedores que vão integrar SMS, voz ou email no próprio sistema usando a BusinessCode.
> **Tempo estimado pra mandar a primeira mensagem:** 5 minutos.

---

## 1. Como funciona em 30 segundos

```
[Seu sistema]  ──POST──▶  [BusinessCode API]  ──▶  [Infobip / SMTP]  ──▶  [Celular / Inbox]
                                  │
                                  └──POST callback──▶  [Seu webhook]  (status: enviado, entregue, falhou)
```

Você faz uma chamada HTTP, debitamos o saldo, mandamos a mensagem, e te avisamos quando chega.

---

## 2. Antes de começar

### Você vai precisar de 3 coisas

**1. Conta + saldo** — entra no painel `https://dash.businesscode.com.br/login` com suas credenciais. Sem saldo, nada sai.

**2. Token de API** — atalho: `Menu → Tokens de API → Gerar token`. Selecione as permissões:
- `messaging:sms` — se for mandar SMS
- `messaging:voice` — se for mandar áudio
- `messaging:email` — se for mandar email
- `messaging:read` — pra consultar status depois

O token aparece UMA VEZ. Copie e guarde em variável de ambiente:
```
BC_TOKEN=37|9sHjAQ9...
```

**3. Webhook (opcional, mas recomendado)** — URL no seu servidor pra receber notificações quando a mensagem é entregue ou falha. Configure em `Menu → Webhooks`.

---

## 3. Mandando a primeira mensagem

### SMS

```bash
curl -X POST https://dash.businesscode.com.br/api/v1/messaging/sms \
  -H "Authorization: Bearer $BC_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+5521980194445",
    "content": "Seu código de acesso é 1234"
  }'
```

**Resposta (sucesso):**
```json
{
  "dispatch_id": 42,
  "status": "queued",
  "credits_reserved": 15,
  "_links": {
    "status": "https://dash.businesscode.com.br/api/v1/messaging/dispatches/42"
  }
}
```

- `dispatch_id` — guarde isso, é o ID da mensagem
- `status: queued` — entrou na fila; em segundos vai virar `sent`, e depois `delivered`
- `credits_reserved: 15` — R$ 0,15 reservado do seu saldo

### Voz — dois modos

**Modo 1: TTS (texto vira voz, mais simples)**

```bash
curl -X POST https://dash.businesscode.com.br/api/v1/messaging/voice \
  -H "Authorization: Bearer $BC_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+5521980194445",
    "content": "Olá, este é um aviso da BusinessCode. Sua entrega chegou."
  }'
```

O destinatário recebe uma ligação e o nosso TTS lê o texto em português.

**Modo 2: Áudio pré-gravado (URL pra MP3/WAV)**

Use quando você já tem um áudio profissional pronto (locução personalizada, jingle, etc.):

```bash
curl -X POST https://dash.businesscode.com.br/api/v1/messaging/voice \
  -H "Authorization: Bearer $BC_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+5521980194445",
    "audio_url": "https://meu-bucket.s3.amazonaws.com/locucao-promocao.mp3"
  }'
```

**Requisitos do arquivo de áudio:**
- Formato: **MP3 ou WAV** (limite Infobip)
- Tamanho: até **4 MB**
- URL deve ser **HTTPS** público (a Infobip baixa o áudio antes da chamada)
- Host precisa estar na allowlist do sistema. Padrão cobre os principais CDNs:
  `s3.amazonaws.com`, `storage.googleapis.com`, `r2.cloudflarestorage.com`, `b-cdn.net`, `blob.core.windows.net`, `digitaloceanspaces.com`, `wasabisys.com`, `backblazeb2.com`.

Pra adicionar seu próprio CDN, fale com o suporte (ajustamos a env `MESSAGING_AUDIO_URL_ALLOWLIST`) ou hospede em um dos da lista.

`content` e `audio_url` são mutuamente exclusivos — passe um OU outro. Se passar os dois, `audio_url` ganha.

### Email

```bash
curl -X POST https://dash.businesscode.com.br/api/v1/messaging/email \
  -H "Authorization: Bearer $BC_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "cliente@exemplo.com.br",
    "subject": "Pedido confirmado",
    "content": "<h1>Obrigado!</h1><p>Seu pedido #ABC123 foi confirmado.</p>",
    "from": "pedidos@parceria.empresa.com.br",
    "from_name": "Empresa Parceria",
    "reply_to": "suporte@parceria.empresa.com.br"
  }'
```

**Campos opcionais:**

- **`from`** — endereço remetente completo. O domínio (`parceria.empresa.com.br`) precisa estar autenticado em [/settings/email-domains](https://dash.businesscode.com.br/settings/email-domains) com status `ativo`. A parte antes do `@` é livre (`pedidos`, `marketing`, `nao-responda`, etc). Se omitir, usamos o remetente padrão da plataforma.
- **`from_name`** — nome amigável que aparece no inbox do destinatário ("Empresa Parceria" em negrito no Gmail/Outlook em vez do email cru). Máx 100 chars.
- **`reply_to`** — quando o destinatário clica em "Responder", a resposta vai pra esse endereço em vez do `from`. Útil pra mandar de `nao-responda@x` e receber respostas em `suporte@x`. Precisa ser email válido.

**Identificação do domínio:**
Você não precisa passar o domínio separado. O sistema extrai automaticamente a parte depois do `@` no `from` e busca em seus domínios autenticados. Um único domínio verificado libera infinitos remetentes (`pedidos@x`, `marketing@x`, `suporte@x`, etc).

Aceita HTML básico no `content`. Scripts (`<script>`) e atributos perigosos (`onerror`, `javascript:`) são removidos automaticamente.

---

## 4. Conceitos importantes

### Idempotency-Key (OBRIGATÓRIO)

Toda chamada de envio precisa do header `Idempotency-Key` com um UUID v4 único. Se você reenviar a mesma chamada com a mesma chave (por exemplo, num retry após timeout), a gente **não cobra de novo nem manda outra mensagem** — devolve o mesmo `dispatch_id`.

**Gere assim:**
- Linux/Mac: `uuidgen`
- Windows PowerShell: `[guid]::NewGuid().ToString()`
- Node.js: `crypto.randomUUID()`
- PHP: `Str::uuid()->toString()`

Validade da chave: **24 horas**. Depois disso uma chave repetida será tratada como nova mensagem.

### Saldo e cobrança

- Tudo em **R$** (centavos no API, formatado em real na UI)
- Preços atuais: `GET /api/v1/account/pricing` retorna o preço de cada serviço PRA VOCÊ (com qualquer desconto que você tenha)
- Saldo atual: `GET /api/v1/account/balance`

**Exemplo: consultar saldo + preço antes de enviar:**
```bash
curl -H "Authorization: Bearer $BC_TOKEN" \
  https://dash.businesscode.com.br/api/v1/account/balance
```
```json
{ "data": { "balance_brl": "R$ 1.000,00", "available_cents": 100000, ... } }
```

### Horário silencioso (quiet hours)

Por padrão, **22h–8h** (horário SP) os envios são bloqueados pra respeitar o destinatário. Resposta: `422 QUIET_HOURS`.

Pra postergar pro próximo horário válido em vez de rejeitar, adicione o header:
```
X-Quiet-Hours-Strategy: defer
```

### Opt-out (descadastro)

Se o destinatário pediu pra não receber mais (respondeu "SAIR" via SMS, ou clicou no link unsubscribe do email), a chamada retorna `422 RECIPIENT_OPTED_OUT`. Você **não é cobrado** nesse caso.

---

## 5. Consultando o status de uma mensagem

```bash
curl -H "Authorization: Bearer $BC_TOKEN" \
  https://dash.businesscode.com.br/api/v1/messaging/dispatches/42
```

**Resposta:**
```json
{
  "data": {
    "id": 42,
    "channel": "sms",
    "to": "+5521980194445",
    "status": "delivered",
    "sale_cents": 15,
    "sent_at": "2026-05-27T19:55:02-03:00",
    "delivered_at": "2026-05-27T19:55:18-03:00",
    "external_message_id": "177983610193604398850734"
  }
}
```

**Estados possíveis:**

| Status | Significa |
|--------|-----------|
| `queued` | Aguardando processamento (poucos segundos) |
| `sending` | Enviando agora |
| `sent` | Aceito pelo provedor (Infobip/SMTP) |
| `delivered` | Confirmado entregue no celular/inbox (atendida no caso de voz) |
| `failed` | Falhou (motivo em `error_message`) |
| `rejected_opt_out` | Bloqueado por opt-out |
| `rejected_quiet_hours` | Bloqueado por horário silencioso |

### Campos específicos de chamada de voz

Quando o status muda pra `delivered` ou `failed` em uma chamada de voz, campos adicionais são preenchidos pelo callback da Infobip:

| Campo | Tipo | Conteúdo |
|-------|------|----------|
| `voice_status` | string | Status granular da chamada (`DELIVERED_TO_HANDSET`, `ANSWERED`, `NO_ANSWER`, `BUSY`, `FAILED`, `REJECTED`, `EXPIRED`) |
| `answered_at` | ISO8601 | Momento em que o destinatário atendeu (null se não atendeu) |
| `ended_at` | ISO8601 | Momento em que a chamada encerrou |
| `call_duration_seconds` | int | Duração efetiva em segundos (do atendimento ao desligamento) |

Exemplo de dispatch após chamada atendida:
```json
{
  "data": {
    "id": 42,
    "channel": "voice",
    "to": "+5521980194445",
    "status": "delivered",
    "sent_at": "2026-05-28T07:00:00-03:00",
    "delivered_at": "2026-05-28T07:00:25-03:00",
    "answered_at": "2026-05-28T07:00:08-03:00",
    "ended_at": "2026-05-28T07:00:25-03:00",
    "call_duration_seconds": 17,
    "voice_status": "DELIVERED_TO_HANDSET"
  }
}
```

Exemplo de não atendida:
```json
{
  "data": {
    "id": 43,
    "channel": "voice",
    "status": "failed",
    "voice_status": "NO_ANSWER",
    "answered_at": null,
    "call_duration_seconds": null,
    "error_message": "Not answered"
  }
}
```

Esses campos também aparecem no payload do webhook outbound (eventos `message.delivered` / `message.failed`) sob a chave `data.call`:
```json
{
  "event": "message.delivered",
  "data": {
    "dispatch_id": 42,
    "channel": "voice",
    "call": {
      "voice_status": "DELIVERED_TO_HANDSET",
      "answered_at": "2026-05-28T07:00:08-03:00",
      "ended_at": "2026-05-28T07:00:25-03:00",
      "duration_seconds": 17
    }
  }
}
```

---

## 6. Webhooks — receber notificações no seu sistema

Em vez de ficar consultando `/dispatches/{id}` em loop, configure um webhook e a gente te avisa quando o status muda.

**Configurar:** `Menu → Webhooks → Novo webhook`
- **URL:** sua URL HTTPS (ex: `https://meu-app.com/bc-webhook`)
- **Eventos:** escolha o que quer receber. Mais comuns:
  - `message.delivered` — entregue
  - `message.failed` — falhou
  - `billing.low_balance` — saldo baixo
- **Secret:** geramos um automaticamente. Salve — usado pra validar a assinatura.

**Payload típico:**
```json
POST https://meu-app.com/bc-webhook
Content-Type: application/json
X-Webhook-Signature: a3b2c1...

{
  "event": "message.delivered",
  "timestamp": "2026-05-27T19:55:18-03:00",
  "data": {
    "dispatch_id": 42,
    "channel": "sms",
    "to": "+5521980194445",
    "status": "delivered",
    "sale_cents": 15,
    "idempotency_key": "uuid-aqui",
    "meta": { "order_id": "ABC123" }
  }
}
```

### Validar a assinatura (segurança)

Sempre valide o header `X-Webhook-Signature` pra ter certeza que veio da gente:

**PHP:**
```php
$expected = hash_hmac('sha256', file_get_contents('php://input'), $YOUR_SECRET);
if (! hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])) {
    http_response_code(401); exit;
}
```

**Node.js:**
```js
const expected = crypto.createHmac('sha256', secret).update(rawBody).digest('hex')
if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.headers['x-webhook-signature']))) {
  return res.status(401).end()
}
```

**Python:**
```python
expected = hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
if not hmac.compare_digest(expected, request.headers['X-Webhook-Signature']):
    return Response(status=401)
```

### Testar o webhook

No painel, ao lado de cada webhook configurado tem um botão **Testar** — dispara um evento `webhook.test` e mostra a resposta do seu servidor em tempo real (status, duração, body). Bom pra validar que sua URL está acessível antes de configurar em produção.

---

## 7. Adicionando metadados às mensagens

Use o campo `meta` pra anexar dados próprios (ex: ID do pedido). Eles voltam intactos nos webhooks, facilitando o tracking:

```json
{
  "to": "+5521980194445",
  "content": "Seu pedido foi enviado",
  "meta": {
    "order_id": "ABC123",
    "user_id": 789,
    "campaign": "remarketing-q3"
  }
}
```

A gente NÃO interpreta nem indexa o conteúdo de `meta` — é opaco. Pode ser qualquer JSON válido.

---

## 8. Códigos de erro mais comuns

| HTTP | Code | O que significa | O que fazer |
|------|------|-----------------|-------------|
| 401 | `Unauthenticated` | Token inválido / expirado / revogado | Gera novo token no painel |
| 402 | `INSUFFICIENT_FUNDS` | Saldo + linha de crédito insuficientes | Recarrega ou aumenta limite |
| 402 | `BILLING_SUSPENDED` | Conta em atraso (grace period excedido) | Atualiza cartão / paga em aberto |
| 402 | `BILLING_BLOCKED` | Conta bloqueada | Entra em contato com suporte |
| 403 | `INSUFFICIENT_TOKEN_ABILITY` | Token não tem a permissão necessária | Gera token novo com a ability certa |
| 409 | `IDEMPOTENCY_KEY_REUSE` | Mesma chave com payload diferente | Use chave UUID nova |
| 422 | `RECIPIENT_OPTED_OUT` | Destinatário descadastrado | Não cobre; remova da sua lista |
| 422 | `QUIET_HOURS` | Tentou enviar 22h–8h | Use `X-Quiet-Hours-Strategy: defer` |
| 429 | `RATE_LIMIT_EXCEEDED` | Excedeu req/min do canal | Implemente backoff exponencial |

**Boa prática de retry:** em qualquer erro 5xx ou de rede, faça retry com a MESMA `Idempotency-Key`. Você não será cobrado duas vezes mesmo se a primeira chamada tiver entrado mas não respondido.

---

## 9. Limites por canal (padrão)

| Canal | Tamanho | Rate limit | Custo padrão* |
|-------|---------|------------|---------------|
| SMS | 1600 chars | 60/min por tenant | R$ 0,15 |
| Voz (TTS) | 600 chars | 10/min por tenant | R$ 0,80 |
| Email | 65k chars (HTML) | 120/min por tenant | R$ 0,05 |

\* Custo PARA VOCÊ pode ser diferente — consulte `GET /api/v1/account/pricing`.

Excedeu rate limit? Resposta `429` com header `Retry-After` indicando quantos segundos esperar.

---

## 10. Perguntas frequentes

**Q: Posso enviar pra qualquer país?**
A: Suportamos qualquer DDI internacional via E.164 (`+5511...`, `+1212...`, `+44...`). Custos podem variar — fale com comercial pra preços fora do Brasil.

**Q: O número precisa estar formatado de alguma forma específica?**
A: Aceita várias formas: `+5521980194445`, `5521980194445`, `(21) 98019-4445`, `+55 21 98019-4445`. A gente normaliza pra E.164 internamente.

**Q: Posso agendar uma mensagem pra horário futuro?**
A: Sim. Adicione `scheduled_for` no payload com data ISO8601 futura (max 30 dias):
```json
{ "to": "...", "content": "...", "scheduled_for": "2026-06-01T10:00:00-03:00" }
```

**Q: Como vejo o histórico de envios?**
A: `GET /api/v1/messaging/dispatches?channel=sms&status=delivered` — filtros por canal e status.

**Q: O que conta como entregue (delivered)?**
A: Quando o Infobip recebe o ACK da operadora (SMS) ou do servidor do destinatário (email). Não significa que o usuário leu — só que chegou.

**Q: E se o destinatário responder?**
A: Se ele responder com "SAIR/STOP/PARAR" no SMS, automaticamente entra na sua lista de opt-out e nunca mais recebe SMS seu. Outras respostas não são tratadas no momento.

**Q: Tem sandbox/ambiente de teste?**
A: Não temos sandbox isolado — você usa o mesmo endpoint com saldo de teste. Recomendamos criar um token com `expires_at` curto pra desenvolvimento e validar primeiro com `webhook.test` no painel.

**Q: Qual SLA?**
A: 99,5% uptime mensal. Status em tempo real: (em breve)

---

## 11. Próximos passos

1. ✅ Gera teu primeiro token em `https://dash.businesscode.com.br/settings/api-tokens`
2. ✅ Roda o curl de SMS aqui em cima com **seu próprio número**
3. ✅ Configura um webhook em `https://dash.businesscode.com.br/settings/webhooks` apontando pro teu servidor
4. ✅ Implementa validação da assinatura HMAC no teu endpoint
5. ✅ Põe Idempotency-Key em todos os envios
6. ✅ Faz consulta de saldo periódica e/ou assina `billing.low_balance` pra não ser pego de surpresa
7. 🚀 Sobe pra produção

---

## 12. Suporte

- 📧 Email: suporte@businesscode.com.br
- 💬 WhatsApp: configurado no rodapé do painel
- 📚 Painel: `https://dash.businesscode.com.br`

---

*Documento atualizado em 27/05/2026. Mudanças importantes na API são comunicadas com 30 dias de antecedência por email.*
