# Checkout com Mercado Pago — Design Spec

**Data:** 2026-04-13
**Status:** Aprovado
**Escopo:** Integração completa de checkout com Mercado Pago (Checkout Transparente), assinaturas recorrentes, compra avulsa de créditos, cupons de desconto.

---

## 1. Visão Geral

Implementar o fluxo de checkout integrado ao Mercado Pago usando Checkout Transparente (in-app), funcionando tanto na página pública de vendas quanto no dashboard do usuário autenticado.

### Fluxos suportados

1. **Assinatura de plano** — recorrência automática no cartão; PIX/boleto para primeiro pagamento (renovação manual)
2. **Compra avulsa de créditos** — valor livre com mínimo, pagamento único (cartão/PIX/boleto)

### Meios de pagamento

- Cartão de crédito (tokenização via MercadoPago.js — dados do cartão nunca passam pelo backend)
- PIX (QR code + copia-e-cola, expiração 30 min, polling automático)
- Boleto bancário (linha digitável + PDF, prazo 1-3 dias úteis)

---

## 2. Backend

### 2.1 Dependência

- `mercadopago/dx-php` (SDK oficial PHP do Mercado Pago)

### 2.2 Variáveis de ambiente (.env)

```
MP_PUBLIC_KEY=APP_USR-xxxxx
MP_ACCESS_TOKEN=APP_USR-xxxxx
MP_WEBHOOK_SECRET=xxxxx
CREDIT_UNIT_PRICE=0.08
CREDIT_MIN_PURCHASE=100
```

### 2.3 Novas Migrations

#### `subscriptions`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | bigIncrements | PK |
| tenant_id | foreignId | FK para tenants |
| plan_id | foreignId | FK para plans |
| mp_subscription_id | string, nullable | ID da subscription no MP |
| mp_payer_id | string, nullable | ID do pagador no MP |
| status | enum | pending, authorized, active, paused, cancelled |
| payment_method | enum | credit_card, pix, boleto |
| current_period_start | date | Início do período atual |
| current_period_end | date | Fim do período atual |
| price | decimal(10,2) | Valor cobrado |
| discount_amount | decimal(10,2), default 0 | Desconto aplicado |
| coupon_id | foreignId, nullable | FK para coupons |
| cancelled_at | timestamp, nullable | Data de cancelamento |
| timestamps | | created_at, updated_at |

#### `payments`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | bigIncrements | PK |
| tenant_id | foreignId | FK para tenants |
| subscription_id | foreignId, nullable | FK para subscriptions (null em compra avulsa) |
| mp_payment_id | string, unique | ID do pagamento no MP |
| type | enum | subscription, credit_purchase |
| status | enum | pending, approved, rejected, refunded, cancelled |
| payment_method | enum | credit_card, pix, boleto |
| amount | decimal(10,2) | Valor bruto |
| net_amount | decimal(10,2), nullable | Valor líquido (após taxas MP) |
| credits_purchased | integer, nullable | Qtd de créditos (compra avulsa) |
| pix_qr_code | text, nullable | Código PIX copia-e-cola |
| pix_qr_code_base64 | text, nullable | QR code em base64 |
| pix_expiration | timestamp, nullable | Expiração do PIX |
| boleto_url | string, nullable | URL do PDF do boleto |
| boleto_barcode | string, nullable | Linha digitável |
| paid_at | timestamp, nullable | Data do pagamento efetivo |
| timestamps | | created_at, updated_at |

#### `coupons`

| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | bigIncrements | PK |
| code | string, unique | Código do cupom (uppercase) |
| discount_type | enum | percentage, fixed |
| discount_value | decimal(10,2) | Valor ou percentual |
| max_uses | integer, nullable | Limite de usos (null = ilimitado) |
| times_used | integer, default 0 | Vezes usado |
| valid_from | date | Início da validade |
| valid_until | date, nullable | Fim da validade (null = sem expiração) |
| active | boolean, default true | Ativo/inativo |
| timestamps | | created_at, updated_at |

### 2.4 Models

**Subscription** — belongsTo Tenant, Plan, Coupon; hasMany Payment
**Payment** — belongsTo Tenant, Subscription (nullable)
**Coupon** — hasMany Subscription

### 2.5 MercadoPagoService

Classe `App\Services\MercadoPagoService`:

```
createSubscription(Plan $plan, string $cardToken, string $payerEmail, ?Coupon $coupon): array
createPayment(array $data): array              // pagamento único (PIX, boleto, ou cartão avulso para créditos)
createPixPayment(float $amount, string $description, string $payerEmail): array
createBoletoPayment(float $amount, string $description, string $payerEmail, string $payerDoc): array
cancelSubscription(string $mpSubscriptionId): bool
processWebhook(array $payload): void
getPaymentStatus(string $mpPaymentId): array
```

### 2.6 Controllers e Endpoints

#### Públicos (sem auth)

| Método | Rota | Controller | Descrição |
|--------|------|------------|-----------|
| POST | /v1/checkout/validate-coupon | CheckoutController@validateCoupon | Valida cupom, retorna desconto |
| POST | /v1/webhooks/mercadopago | WebhookController@mercadopago | Processa notificações do MP |

#### Autenticados

| Método | Rota | Controller | Descrição |
|--------|------|------------|-----------|
| POST | /v1/subscriptions | SubscriptionController@store | Cria assinatura (cartão recorrente) |
| GET | /v1/subscriptions/current | SubscriptionController@current | Assinatura ativa do tenant |
| POST | /v1/subscriptions/cancel | SubscriptionController@cancel | Cancela assinatura |
| POST | /v1/payments/pix | PaymentController@pix | Gera pagamento PIX |
| POST | /v1/payments/boleto | PaymentController@boleto | Gera pagamento boleto |
| POST | /v1/payments/credits | PaymentController@credits | Compra avulsa de créditos |
| GET | /v1/payments | PaymentController@index | Histórico de pagamentos |

#### Admin

| Método | Rota | Controller | Descrição |
|--------|------|------------|-----------|
| GET | /v1/admin/coupons | CouponController@index | Lista cupons |
| POST | /v1/admin/coupons | CouponController@store | Cria cupom |
| PUT | /v1/admin/coupons/{id} | CouponController@update | Atualiza cupom |
| DELETE | /v1/admin/coupons/{id} | CouponController@destroy | Remove cupom |

### 2.7 FormRequests

- `CreateSubscriptionRequest` — valida plan_id, card_token, payer_email, coupon_code (opcional)
- `CreatePixPaymentRequest` — valida plan_id ou credits_amount, payer_email
- `CreateBoletoPaymentRequest` — valida plan_id ou credits_amount, payer_email, payer_document (CPF/CNPJ)
- `PurchaseCreditsRequest` — valida credits_amount (>= CREDIT_MIN_PURCHASE), payment_method
- `ValidateCouponRequest` — valida code
- `StoreCouponRequest` — valida code, discount_type, discount_value, valid_from, etc.

### 2.8 Webhook Processing

**Endpoint:** `POST /v1/webhooks/mercadopago`

**Validação:**
- Verifica assinatura HMAC via header `x-signature` com `MP_WEBHOOK_SECRET`
- Rejeita sem assinatura válida (401)
- Idempotência: ignora `mp_payment_id` já processado

**Eventos:**

| Evento MP | Ação |
|-----------|------|
| payment.approved (type=subscription) | Ativa subscription, status tenant=active, credita credits_included |
| payment.approved (type=credit_purchase) | Soma credits_purchased ao credits_balance do tenant |
| payment.pending | Mantém status pending |
| payment.rejected | Marca rejected, notifica usuário |
| subscription_preapproval.authorized | Confirma recorrência ativa |
| subscription_preapproval.paused | Subscription paused, tenant suspenso |
| subscription_preapproval.cancelled | Subscription cancelled, downgrade para free |

**Resposta:** Sempre 200 OK (processa em background via job queue se necessário).

---

## 3. Frontend

### 3.1 Novas Rotas

| Rota | Layout | Auth | Componente |
|------|--------|------|------------|
| /settings/checkout/:planSlug | Dashboard (sidebar) | Sim | CheckoutPage.vue |
| /settings/credits | Dashboard (sidebar) | Sim | CreditPurchase.vue |
| /plans | Público (sem sidebar) | Não | PricingPlans.vue |
| /plans/checkout/:planSlug | Público (sem sidebar) | Sim* | CheckoutPage.vue |
| /checkout/thank-you | Adaptativo | Sim | CheckoutThankYou.vue |

*Redireciona para login se não autenticado, retorna ao checkout após login.

### 3.2 Componentes

#### CheckoutPage.vue (página)
- Recebe `planSlug` da rota, carrega dados do plano via API
- Layout 2 colunas: esquerda (CheckoutForm) + direita (OrderSummary sticky)
- Detecta origin (dashboard vs público) para adaptar layout/navbar
- Suporta tanto assinatura quanto compra de créditos (via query param `?type=credits&amount=500`)

#### CheckoutForm.vue (componente core compartilhado)
- Props: `plan`, `origin` (dashboard/public), `type` (subscription/credits), `amount` (para créditos)
- 3 tabs: Cartão de Crédito | PIX | Boleto
- **Tab Cartão:** campos card number, expiry, CVC, nome no cartão. Tokenização via MercadoPago.js SDK — cria `cardToken` no frontend, envia ao backend
- **Tab PIX:** botão "Gerar PIX" → chama API → renderiza componente PixPayment
- **Tab Boleto:** campos CPF/CNPJ → chama API → renderiza componente BoletoPayment
- Loading states em todos os botões, desabilita double-click
- Emite `@payment-success` e `@payment-error`

#### OrderSummary.vue (sidebar)
- Props: `plan`, `coupon`, `type`, `creditsAmount`
- Exibe: nome do plano (ou "Compra de Créditos"), preço, desconto, total
- Campo de cupom com botão "Aplicar" (chama validate-coupon)
- Botão "Finalizar Compra" — dispara submit do CheckoutForm
- Badges: SSL Secured, Mercado Pago

#### PricingPlans.vue (página pública)
- Hero com título + toggle mensal/anual
- Grid 3 colunas: Starter (free), Pro (destaque), Enterprise (custom)
- Features list por plano
- Botões: "Escolher Plano" → navega para checkout, "Falar com Vendas" (Enterprise)
- Seção trust logos + comparação de features
- Layout full-width sem sidebar

#### CheckoutThankYou.vue (página)
- Ícone de sucesso animado
- Mensagem personalizada: "Bem-vindo ao plano {planName}, {userName}!"
- Dados da transação: Order ID, data, valor
- 3 cards de próximos passos: Criar Campanha, Importar Contatos, Ver Dashboard
- Botões: Visitar Suporte, Baixar Recibo

#### PixPayment.vue (sub-componente)
- QR code renderizado (imagem base64) + código texto com botão copiar
- Timer regressivo de expiração (30 min)
- Polling automático a cada 5s via `GET /payments/{id}/status`
- Max 3 falhas consecutivas no polling antes de parar
- Ao aprovar: redireciona para ThankYou

#### BoletoPayment.vue (sub-componente)
- Linha digitável com botão copiar
- Link/botão para abrir PDF do boleto
- Aviso: "O pagamento por boleto leva 1-3 dias úteis para ser confirmado"
- Sem polling ativo — confirmação vem via webhook

#### CreditPurchase.vue (página)
- Input numérico de quantidade de créditos (mínimo: CREDIT_MIN_PURCHASE)
- Cálculo em tempo real do valor (quantidade x CREDIT_UNIT_PRICE)
- Slider ou input com step de 100
- Reutiliza CheckoutForm para pagamento
- Acessível via botão "Comprar Créditos" na página de Plans e no dashboard

### 3.3 Pinia Store — useCheckoutStore

```typescript
State:
  selectedPlan: Plan | null
  paymentMethod: 'credit_card' | 'pix' | 'boleto'
  coupon: Coupon | null
  paymentStatus: 'idle' | 'processing' | 'success' | 'error'
  currentSubscription: Subscription | null
  currentPayment: Payment | null      // para polling PIX

Actions:
  createSubscription(planId, cardToken, payerEmail, couponCode?)
  createPixPayment(planId, payerEmail)
  createBoletoPayment(planId, payerEmail, payerDoc)
  purchaseCredits(amount, paymentMethod, cardToken?, payerDoc?)
  validateCoupon(code): Coupon
  pollPaymentStatus(paymentId): void   // polling PIX a cada 5s
  fetchCurrentSubscription(): void
  cancelSubscription(): void
```

### 3.4 Atualização de componentes existentes

**Plans.vue (settings):**
- Substituir botão "Falar com vendas" por "Fazer Upgrade" → navega para `/settings/checkout/:planSlug`
- Adicionar botão "Comprar Créditos" → navega para `/settings/credits`
- Mostrar badge de assinatura ativa com data de renovação

---

## 4. Segurança

- **PCI Compliance:** dados do cartão tokenizados no frontend via MercadoPago.js. Backend recebe apenas `card_token`
- **Webhook HMAC:** validação obrigatória da assinatura `x-signature`
- **Rate limiting:** checkout 10 req/min por IP, webhook 100 req/min, validate-coupon 20 req/min
- **FormRequests:** validação server-side em todos os endpoints
- **Logs:** nunca logar dados de cartão; logar payment_id, tenant_id, status, error_code
- **CSRF:** não aplicável (API stateless com Sanctum token)

---

## 5. Tratamento de Erros

### Frontend
- Loading states em todos os botões (desabilita re-click)
- Toasts de erro amigáveis:
  - Cartão recusado → "Cartão recusado. Verifique os dados ou tente outro cartão."
  - PIX expirado → "QR Code expirado. Gere um novo."
  - Cupom inválido → "Cupom inválido ou expirado."
  - Créditos abaixo do mínimo → "Mínimo de {min} créditos."
  - Erro genérico → "Erro ao processar pagamento. Tente novamente."
- Retry no polling PIX (max 3 falhas seguidas)

### Backend
- Try/catch em todas as chamadas à API do MP
- Log estruturado: `[MercadoPago] payment_id={id} tenant_id={id} status={status} error={msg}`
- Webhook: processa em job queue, retry em caso de falha
- Retorno de erro padronizado: `{ error: string, code: string, details?: object }`

---

## 6. Testes

- **Unitários:** MercadoPagoService (mock API), cálculo de cupom, cálculo de créditos avulsos
- **Feature:** fluxo checkout cartão/PIX/boleto, webhook processing, validação de cupom, compra de créditos
- **Pentest:** card data não logada, webhook signature, rate limiting, SQL injection nos inputs
