# Relatório Consolidado — Pré-lançamento CampaignAI
**Data:** 2026-05-28
**Responsável:** Gerente de Projeto Sênior
**Insumos:** 4 relatórios técnicos independentes (UX/UI, Dev, QA, Red Team)

---

## 1. Veredito Executivo

- **NÃO PODE LANÇAR.** Foram consolidados **31 bloqueadores P0 únicos** (após mesclar 11 duplicatas entre especialistas), cobrindo perda silenciosa de receita, fraude financeira direta, violação imediata de LGPD, SSRF/IDOR/mass-assignment e UI de cobrança inconsistente.
- **Três classes de risco intoleráveis convergem:** (1) cobrança feita DEPOIS do envio em batch com possibilidade de não ocorrer (Dev/QA), (2) preço exibido divergente do cobrado em 3 telas (UX) somado a duplicação de crédito MP (Red Team), (3) ausência de opt-out e auditoria visível (UX/QA) violando LGPD desde o primeiro disparo.
- **Bug funcional confirmado em produção potencial:** `ReportController::credits` filtra `type=debit|credit` que não existem no enum — toda página "Extrato" mostra zero. Cliente abre, vê zerado, perde confiança.
- **Vulnerabilidades críticas de segurança não corrigidas de auditoria anterior** (regressão): secret de webhook Infobip aceito via query string + SSRF em outbound webhooks que permite roubo de credenciais EC2/IMDS.
- **Estimativa de prazo até go-live:** **6 a 8 semanas** se priorizado em 6 sprints com time de 3-4 devs + 1 QA + 1 UX/PM. Considera 31 P0 + os 12 gaps de PM (runbooks, SLA, monitoramento, backup/restore, suporte).

**Pode lançar?** **NÃO.** Lançar agora expõe a empresa a (a) autuação ANPD/Procon na primeira denúncia (caminho de campanha não honra opt-out, LGPD art. 18), (b) prejuízo financeiro direto via double-credit MP (Red Team SEC-04-4.3) e via envio sem cobrança quando `Bus::batch::then()` não executa (QA-DISP-01/Dev BLOQUEADOR-06), (c) takeover de tenant via mass-assignment de role (Red Team SEC-04-2.2). Cada um destes individualmente é "stop the world".

---

## 2. Matriz de Bloqueadores Consolidados (P0)

| ID | Tema | Descrição curta | Origem | Impacto | Ordem | Esforço |
|---|---|---|---|---|---|---|
| P0-01 | Cobrança pós-batch + saldo negativo | `ProcessCampaignJob::then()` debita só após batch concluído; se Horizon cair ou notification falhar, mensagens enviadas sem cobrança. Sem `WithoutOverlapping`. (`ProcessCampaignJob.php:91-110`, `BillingService.php:22-85`) | Dev BLQ-06+07+12, QA-DISP-01 | Receita | 1 | L |
| P0-02 | Duplo disparo trivial | `sendNow` sem `lockForUpdate`/`WithoutOverlapping`+ falta UNIQUE em `campaign_dispatches(campaign_id, contact_id)`. Dois cliques = 2 batches, gasto duplicado. (`CampaignsController.php:127-149`, migration `2026_03_12_160010`) | Dev BLQ-05+ATN-03, QA-DISP-02, UX `Create.vue:198` | Receita | 1 | M |
| P0-03 | Preço divergente em 3 telas | `Plans.vue:77` vs `ConfirmSendModal.vue:91` vs `Step3Contacts.vue:190` exibem valores diferentes; backend usa preço dinâmico não-mostrado. CDC art. 31. | UX | Receita/Compliance | 2 | M |
| P0-04 | Snapshot de preço ausente | `PricingService::priceFor` lido só DEPOIS do envio; admin pode alterar tabela durante batch e cobrar valor diferente do anunciado. Sem snapshot em `Campaign` ou `CampaignDispatch`. (`PricingService.php:22-52`) | Dev BLQ-13 | Receita/Compliance | 2 | M |
| P0-05 | Double-credit MP (fraude) | `PaymentController::credits` chama `recharge` sincronicamente E webhook MP chama de novo. Sem UNIQUE em `balance_transactions(reference_type, reference_id, type)`. Atacante paga R$100 → recebe R$200. (`PaymentController.php:267-315`) | RT SEC-04-4.3+4.6, Dev BLQ-14 | Receita/Fraude | 1 | M |
| P0-06 | Caminho de campanha não honra opt-out | `SendCampaignBatchJob` não chama `OptOutService::isOptedOut`. LGPD art. 18 violado em todo disparo em massa. (`SendCampaignBatchJob.php:113-139`) | QA-LGPD-01+DISP-04, UX | Compliance/Legal | 1 | S |
| P0-07 | Ausência de opt-out na UI | Zero matches `grep opt.?out\|unsubscribe\|descadastrar`. Cliente brasileiro fora de LGPD/10DLC/CAN-SPAM na largada. | UX | Compliance/Legal | 1 | M |
| P0-08 | Email de campanha sem link unsubscribe | `SendCampaignBatchJob.php:168-177` chama `sendEmail` direto, sem gerar token. CAN-SPAM/LGPD violados. | QA-DISP-08 | Compliance/Legal | 1 | S |
| P0-09 | Quiet-hours não aplicado em campanhas | `QuietHoursService` só roda em `MessagingService::dispatch` da API transacional; agendador/batch ignora. SMS noturno = multa Procon. | QA-CAMP-03+LGPD-04 | Compliance | 2 | S |
| P0-10 | Audit log mutável + lacuna massiva | Operações financeiras (payment, recharge, manual_adjustment, subscription) e `campaign.schedule/cancel` sem `AuditLog::record`; tabela permite UPDATE/DELETE. LGPD art. 37. (`AuditLog.php:9-50`, `BillingService.php` inteiro) | Dev BLQ-18+19, QA-AUD-02, UX (audit invisível) | Compliance | 2 | M |
| P0-11 | Tela de audit log inexistente para tenant | Nenhuma rota `/settings/audit-log` no router. `CreditLimitModal` cita audit mas não há onde consultar. SOC2/LGPD. | UX | Compliance/UX | 3 | M |
| P0-12 | From-email spoofing em campanhas | `SendCampaignBatchJob.php:53-55` lê `settings.from_email` sem validar `EmailSenderDomain` ownership (validação só existe em `SendEmailRequest`). Phishing usando reputação da plataforma. | Dev BLQ-08 | Segurança/Compliance | 2 | S |
| P0-13 | SSRF não mitigada em outbound webhooks | `FireOutboundWebhookJob` + `WebhooksController::test` aceitam URLs internas (`127.0.0.1`, `169.254.169.254` IMDS AWS, RFC1918). Roubo de credenciais EC2. | RT SEC-04-8.1 | Segurança | 1 | M |
| P0-14 | Secret webhook Infobip em query string | `InfobipWhatsAppWebhookController.php:36` aceita `?secret=` — vaza em access log/Referer/proxy. **Regressão** de finding anterior. | RT SEC-04-3.1 | Segurança | 1 | S |
| P0-15 | Mass-assignment de `role`/`tenant_id` em User | `User.php:22-31` mantém `role` e `tenant_id` em `$fillable`. Qualquer `update($request->all())` futuro = escalada a superadmin. | RT SEC-04-2.2 | Segurança | 1 | S |
| P0-16 | Mass-assignment de `tenant_id` em Campaign | `CampaignsController::store` não rejeita `tenant_id` no payload; trait só injeta se vazio. Atacante cria campanha em outro tenant. | RT SEC-04-2.3, Dev BLQ-02 | Segurança/Isolamento | 1 | S |
| P0-17 | IDOR potencial em EmailDomainsController | `show/verify/destroy` sem `where('tenant_id')` explícito — depende 100% do GlobalScope. Sem defesa em profundidade. | RT SEC-04-2.1 | Segurança | 2 | S |
| P0-18 | InfobipWhatsAppWebhook devolve 500 quando sem secret | Vaza estado interno (vs 401 esperado). Comportamento divergente de `WebhookController::infobipDelivery`. (`InfobipWhatsAppWebhookController.php:43-45`) | Dev BLQ-26 | Segurança | 2 | S |
| P0-19 | WebhookController MP processa sem validar formato | `processWebhook` aceita `type=''`; `request_id` vazio quebra idempotência. Pagamento pode não ser processado silenciosamente. (`WebhookController.php:22-75`) | Dev BLQ-25, QA-WH-02 | Receita/Confiabilidade | 2 | S |
| P0-20 | Webhook delivery aplica status em replays | `WebhookController::infobipDelivery` não dedup por `(messageId, status)`. Reentrega atualiza `delivered_at` 2x + flood de outbound webhooks. | QA-WH-01 | Confiabilidade | 3 | S |
| P0-21 | Channel não-licenciado pode ser criado | `CampaignsController::store` não chama `TenantChannel::isAvailable`; UX dá erro só no Step 3. | Dev BLQ-01, UX `Step2WhatsApp.vue` | UX/Receita | 3 | S |
| P0-22 | Botões fantasma críticos | "Cancelar agendamento" (`Detail.vue:17`), "Redefinir failed" (`Detail.vue:19`), "Ver todos dispatches" (`reports/CampaignDetail.vue:134`) sem handler. Cliente não consegue cancelar campanha após erro de copy. | UX | UX/Operacional | 2 | S |
| P0-23 | Recarga aceita amount negativo | `BillingService::recharge` faz `decrement(-X)` sem `assert > 0`. Webhook MP malformado reduz saldo. | QA-BILL-02 | Receita | 1 | S |
| P0-24 | MercadoPago force `status=authorized` | `MercadoPagoService.php:55` força string literal — pode marcar subscription como ativa que está pending na MP. | Dev BLQ-15 | Receita | 2 | M |
| P0-25 | Saldo `∞` opaco | `Create.vue:143`, `Step5Review.vue:90` exibem glifo sem tooltip. Admin pode disparar achando que tem custo zero. | UX | UX/Receita | 4 | S |
| P0-26 | Banner saldo só dispara em negativo | `BalanceBanner.vue:43-54` não alerta cliente prepaid com saldo positivo baixo antes do disparo. | UX | UX/Receita | 3 | S |
| P0-27 | Recarga sem máscara monetária | `Saldo.vue:91-100` aceita `999` como R$ 999. Em mobile pt-BR sem dot key, cobrança 100x. | UX | Receita | 2 | S |
| P0-28 | API recarga: unidade ambígua | `purchaseCredits(amountInCents)` vs backend `credits_amount` — um deploy pode inverter cobrança 100x. (`Saldo.vue:215`) | UX | Receita | 2 | S |
| P0-29 | `confirm()` nativo em delete de lista | `contacts/Index.vue:347` usa `window.confirm`. Não estilizável, quebra estética. **REBAIXADO de P0 para P1** — cosmético; ver P1-01. | UX | UX | — | — |
| P0-30 | Filtro WhatsApp omitido em ReportController | `ReportController.php:25` valida `in:sms,voice,email`; UI WhatsApp quebra 422. | Dev ATN-21, QA-REPORT-04, UX | UX/Receita | 3 | S |
| P0-31 | WhatsApp invisível na UI de relatórios/campanhas | `channelMap`/`channelBadge` não incluem WhatsApp em 4 lugares. Cliente WA-only não filtra/identifica. | UX | UX | 3 | S |
| P0-32 | Modal de confirmação não-acessível | `Create.vue:209-232` sem teleport/foco/Esc/aria-modal. WCAG 2.1 falha; em telas longas fica atrás do scroll. **REBAIXADO para P1** — bug de acessibilidade, não impede transação. Ver P1-02. | UX | Compliance/UX | — | — |
| P0-33 | Auto-save engole erros 422/500 | `Create.vue:336-340` `catch { /* silent */ }`. Cliente acha que salvou e perde horas. | UX | UX/Receita | 2 | S |
| P0-34 | `configUrl()` aponta para rotas 404 | `SelectChannel.vue:151-159` retorna `/settings/email/sms/voice` inexistentes. | UX | UX | 3 | S |
| P0-35 | `ReportController::credits` retorna zero sempre | Filtra `type IN (debit, credit)` que não existem no enum (`reserve, release, recharge, manual_adjustment`). Cliente vê extrato zerado. **Bug funcional confirmado.** (`ReportController.php:215-258`) | QA-REPORT-01 | Confiança/UX | 1 | S |
| P0-36 | Export CSV sem RBAC | `ReportController::export` só checa `ensureTenantOwns`, não role. Viewer/operator baixa CSV completo com telefones. | QA-REPORT-02 | LGPD/Segurança | 2 | S |
| P0-37 | Race em `lockCampaignIfInsufficient` | `BillingService.php:193-231` calcula `available` sem `lockForUpdate`. Dois `sendNow` paralelos passam o check, debitam 2x. | QA-CONC-01 | Receita | 1 | S |

**Total P0 consolidado: 35 itens (após mesclar duplicatas) — 31 ativos + 2 rebaixados a P1 (P0-29, P0-32) + 2 dependentes (P0-04 sub de P0-03; mas listei separado por escopo técnico).**

> Notas de promoção/rebaixamento:
> - **P0-29** (window.confirm) rebaixado: cosmético, não bloqueia receita/dados.
> - **P0-32** (modal acessibilidade) rebaixado: viola WCAG mas não impede transação; cliente brasileiro PME tolera. Voltar como P1.
> - **Promovido (era P1):** QA-BILL-02 recharge negativo → P0-23 (manipula dinheiro), QA-REPORT-02 export sem RBAC → P0-36 (LGPD), QA-CONC-01 race no insufficient → P0-37.

---

## 3. Matriz de Pontos de Atenção (P1)

| ID | Tema | Descrição curta | Origem | Impacto | Ordem | Esforço |
|---|---|---|---|---|---|---|
| P1-01 | `window.confirm` nativo | `contacts/Index.vue:347` — usar `ConfirmModal` existente. (rebaixado de P0-29) | UX | UX | 5 | S |
| P1-02 | Modal sem teleport/foco/Esc | `Create.vue:209-232`. (rebaixado de P0-32) | UX | UX/Compliance | 5 | S |
| P1-03 | TenantContext static (race com Octane/Redis multi-worker) | `TenantContext.php:5-22` armazena tenant em static; vaza quando escalar. | Dev ATN-09 | Segurança/Escalabilidade | 4 | M |
| P1-04 | Regex telefone inconsistente | Controller `^\+[1-9]\d{6,14}$` vs Job `^\+?[1-9]\d{6,14}$`. Mínimo 7 dígitos aceita números falsos. | Dev ATN-10, UX `Step3Contacts.vue:212` | Confiabilidade | 4 | S |
| P1-05 | `estimated_contacts=0` para lista vazia passa silencioso | Dispara campanha que envia zero mensagens sem alerta. | Dev ATN-04 | UX | 4 | S |
| P1-06 | `scheduled_at` aceita data passada em `store` | Validado só em `update`/`schedule`. | QA-CAMP-02 | Confiabilidade | 4 | S |
| P1-07 | `adhoc_phones` sem dedup | Mesmo número 100x = 100 cobranças. | QA-CAMP-05 | Receita | 4 | S |
| P1-08 | `audio_url` qualquer host | SSRF/tracking via Infobip TTS baixando URL arbitrária. | QA-CAMP-04, RT SEC-04-7.3 | Segurança | 4 | S |
| P1-09 | Tenant `blocked/suspended` sem guard de rota | `router/index.ts:259-282` deixa entrar; descobre só ao agir. | UX | UX | 5 | S |
| P1-10 | Sanctum sem rotação após `changePassword` | Tokens antigos sobrevivem 8h pós troca. (`AuthController.php:183-198`) | RT SEC-04-1.1 | Segurança | 4 | S |
| P1-11 | Login throttle só por IP (credential stuffing) | RateLimiter precisa `email\|ip`. | RT SEC-04-1.2 | Segurança | 4 | S |
| P1-12 | Coupon `times_used` checado fora do lock | Race em `coupon_usages` sem UNIQUE constraint. | Dev ATN-17, RT SEC-04-4.2 | Receita | 4 | S |
| P1-13 | `Subscription::store` sem lock no tenant | 2 POSTs paralelos = 2 subscriptions ativas locais. | Dev ATN-16 | Receita | 4 | S |
| P1-14 | CSP `unsafe-inline` script + style | XSS pós-inbound WhatsApp não bloqueado. | RT SEC-04-10.1 | Segurança | 5 | M |
| P1-15 | `EmailController::sanitizeHtml` por regex | Burlável; usar HTMLPurifier. | RT SEC-04-10.2 | Segurança | 5 | S |
| P1-16 | Cupom brute-force | 10 req/min/IP é insuficiente; cupons curtos. | RT SEC-04-4.1 | Receita | 5 | S |
| P1-17 | Plano desativado ainda cobra | `PaymentController::pix/boleto` não filtra `is_active`. | RT SEC-04-4.4 | Receita | 5 | S |
| P1-18 | Prompt injection no `AiGeneratorController` | Inputs concatenados sem delimitadores. | RT SEC-04-6.2 | Segurança/PII | 5 | M |
| P1-19 | Custo IA cobrado fixo (10 cents) | Grok cobra por token; tenant paga $0.05 e debita 10 cents. | RT SEC-04-6.1 | Receita | 5 | M |
| P1-20 | Logs com PII (telefone em claro) | LGPD art. 6; sem retenção. | RT SEC-04-12.1 | LGPD | 5 | S |
| P1-21 | `webhook_deliveries.request_body` em texto puro | PII + payload sem retention/encryption. | RT SEC-04-12.3 | LGPD | 5 | M |
| P1-22 | `ReportController::campaign` 6 COUNTs separados | Em 100k dispatches custa caro; usar SUM CASE WHEN. | Dev ATN-22 | Performance | 5 | S |
| P1-23 | `ReportController::export` sem limite total | `chunk(500)` ok, mas 10M linhas derruba PHP-FPM. | Dev ATN-24 | Performance/DoS | 5 | M |
| P1-24 | `ReportController::credits` superadmin vê tudo | Sem `tenant_id` explícito; confia 100% no scope. | Dev ATN-23 | UX/Segurança | 5 | S |
| P1-25 | `InboundWebhookController` JSON-path sem índice | Table-scan em `tenant_channels.config->identifier`. | Dev ATN-28 | Performance | 5 | S |
| P1-26 | `FireOutboundWebhookJob` HMAC sem JSON_UNESCAPED | Hash divergente receiver vs emissor. | Dev ATN-29 | Confiabilidade | 5 | S |
| P1-27 | Re-execução de batch duplica `sent_count` | `DB::raw("sent_count + {$x}")` em retry conta 2x. | QA-DISP-07 | Receita | 4 | M |
| P1-28 | Cancel do `Bus::batch` deixa campanha parcial sem conciliação | Sem fluxo de release/refund para parte enviada. | QA-DISP-06 | Receita | 5 | M |
| P1-29 | Provider 200-OK + status REJECTED no webhook | `InfobipService::sendSms` cobra mesmo se MSG é rejeitada. | QA-BILL-05 | Receita | 5 | M |
| P1-30 | `BillingService::release` sem dedup | Double-release possível em retry. | QA-BILL-04 | Receita | 5 | S |
| P1-31 | Saldo card sem "saldo após envio" no Review | Inconsistência com ConfirmSendModal. (`Step5Review.vue:73-98`) | UX | UX | 5 | S |
| P1-32 | Fuso fixo Brasília GMT-3 | Ignora horário de verão e usuários de outros fusos. | UX `Step4Schedule.vue:55` | UX/Confiabilidade | 5 | S |
| P1-33 | Áudio de notificação sem mute | `conversations/Index.vue:70`. WCAG 1.4.2. | UX | UX/Compliance | 5 | S |
| P1-34 | Cores hardcoded `style="color:#..."` | Quebra dark-mode contrast. | UX | UX | 5 | M |
| P1-35 | Nº fallback WhatsApp suporte | `Saldo.vue:181` `5511999999999`. Em prod cliente clica e cai em número aleatório. | UX | UX/Reputação | 4 | S |
| P1-36 | "Bem-vindo ao plano Pro" fallback | `CheckoutThankYou.vue:9,47`. Cliente Starter vê Pro. | UX | UX | 5 | S |
| P1-37 | `nextBillingLabel` sem timezone tenant | UTC-5 vê data errada. (`Saldo.vue:155-166`) | UX | Confiabilidade | 5 | S |
| P1-38 | Plano grátis sem opção de downgrade | Cliente pago não consegue voltar. | UX | UX/Retenção | 5 | M |
| P1-39 | Sem fatura/recibo NF download | Empresas BR precisam para contabilidade. | UX | UX/Compliance | 4 | M |
| P1-40 | Webhook MP sem timestamp/replay protection | `ibm-signature-v2` não inclui ts; replay infinito. | RT SEC-04-3.2 | Segurança | 5 | S |
| P1-41 | `WhatsApp media_url` salvo sem allowlist | SSRF potencial. | RT SEC-04-3.3 | Segurança | 5 | S |
| P1-42 | `SANCTUM_STATEFUL_DOMAINS` com localhost default | Garantir override em prod. | RT SEC-04-13.2 | Segurança/Deploy | 4 | S |
| P1-43 | Throttle `campaign-dispatch` 20/min permite 200k SMSs/min | Cap em estimated_contacts por hora. | RT SEC-04-11.2 | Receita/DoS | 5 | S |
| P1-44 | `ContactsController::batch` sem transação | `contact_count` inconsistente em crash. | QA-CONC-03 | Confiabilidade | 5 | S |
| P1-45 | Auto-save sem timestamp visível | UX `Create.vue:28`. | UX | UX | 5 | S |
| P1-46 | Step1 valida só `name` para avançar | Avança sem `template/audio/content` pronto. | UX `Create.vue:307-319` | UX | 5 | S |
| P1-47 | `currentStepMax` não regride | Pílulas continuam clicáveis após perder validação. | UX `Create.vue:269,276` | UX | 5 | S |
| P1-48 | `route.query.channel` aceito sem checar tenant | Entra no fluxo e falha no envio. | UX `Create.vue:545-550` | UX | 5 | S |
| P1-49 | `EmailDomains` onboarding aberto sempre | UX `EmailDomains.vue:17-87`. | UX | UX | 5 | S |
| P1-50 | Step2WhatsApp empty state sem CTA navegável | Usuário preso. | UX | UX | 5 | S |
| P1-51 | `Step3Contacts` regex 7 dígitos aceita números falsos | Reforço de P1-04 no frontend. | UX | UX/Receita | 5 | S |
| P1-52 | Coupon `Plan::isActive` sem filtro | Reforço P1-17. | RT | Receita | 5 | S |
| P1-53 | Webhook test response body de 2000 chars vazado | Exfiltração de internos. | RT SEC-04-8.2 | Segurança | 5 | S |
| P1-54 | Páginação inconsistente (`<>` vs números) | UX `Detail.vue:150-159`. | UX | UX | 6 | S |
| P1-55 | Sidebar Step2 mobile sem CTA flutuante | UX `Create.vue:147-152`. | UX | UX | 6 | S |
| P1-56 | `Coupon::isValid()` race apesar do lock | Reforço P1-12 com refresh entre lock e check. | Dev ATN-17 | Receita | 5 | S |

**Total P1 consolidado: ~56 itens.**

---

## 4. Melhorias Recomendadas (P2)

| ID | Tema | Origem | Esforço |
|---|---|---|---|
| P2-01 | Página `/help` com FAQ (opt-out, DNS, billing) | UX | M |
| P2-02 | Onboarding tour guiado (Shepherd/Driver.js) primeiro login | UX | M |
| P2-03 | Atalhos teclado Ctrl+Enter/Ctrl+S | UX | S |
| P2-04 | Preview destinatário aleatório com variáveis preenchidas | UX | S |
| P2-05 | Estimativa de tempo de disparo (5k contatos ≈ 12min) | UX | S |
| P2-06 | Calculadora "quanto recarregar?" baseada em histórico | UX | M |
| P2-07 | Auto-recarga (top-up) quando saldo < X | UX | M |
| P2-08 | Comparativo planos com slider de uso projetado | UX | M |
| P2-09 | Drill-down KPI no `Detail.vue` (clicar falhas → filtra) | UX | S |
| P2-10 | Export agendado (relatório semanal por email) | UX | M |
| P2-11 | Heatmap horário de melhor entrega por canal | UX | M |
| P2-12 | Comparação período a período (vs anterior) | UX | M |
| P2-13 | Skip-to-content link WCAG 2.4.1 | UX | S |
| P2-14 | Padronizar `prefers-reduced-motion` | UX | S |
| P2-15 | Pentest: `CampaignSecurityTest` + `WebhookSecurityTest` | Dev MEL-36 | M |
| P2-16 | Teste concorrência state machine | Dev MEL-37 | M |
| P2-17 | Teste regressão `estimated_contacts` fraude | Dev MEL-38 | S |
| P2-18 | Teste `auth.login_failed` PII retention | QA-AUD-04 | S |
| P2-19 | Smoke E2E HappyPathTest (registra→assina→importa→dispara→relatório) | QA-Conclusão-1 | M |
| P2-20 | Teste anonimização pós-LGPD-request | QA-LGPD-06 | M |
| P2-21 | Tooltip "Quem editou esta campanha?" | UX | S |
| P2-22 | Indicador progresso de auto-save com timestamp | UX | S |
| P2-23 | Endpoint export LGPD `/api/v1/lgpd/export` | QA-LGPD-05 | M |

---

## 5. Gaps Identificados Apenas pelo PM

Itens críticos que NENHUM dos 4 especialistas levantou e que devem ser considerados pré-lançamento:

| ID | Tema | Por que é crítico | Esforço |
|---|---|---|---|
| PM-G01 | **Runbook de incidentes** para fila parada, MP fora do ar, Infobip 500, banco saturado. Sem isso, suporte vira chaos engineering em produção. | Operacional | M |
| PM-G02 | **Alertas (Sentry + Prometheus/Grafana)** para: workers parados, saldo de tenants estratégicos < limite, fila de jobs > N pendentes, taxa de erro MP/Infobip > X%, falhas de webhook outbound. Hoje não há observabilidade citada em nenhum relatório. | Operacional | L |
| PM-G03 | **Plano de backup/restore** documentado e testado para MySQL + storage de imports/audio. Quando o disco morrer, qual o RPO/RTO? | Operacional/Compliance | M |
| PM-G04 | **Disaster recovery / plano de rollback do deploy**. Hoje deploy é one-way; em produção, deve haver feature flag + canary + rollback automatizado se taxa de erro subir. | Operacional | L |
| PM-G05 | **Release notes/changelog visível ao tenant** ("v1.2 — adicionamos WhatsApp"). Sem isso, cliente não sabe o que mudou e pensa que sistema está bugado. | Comunicação | S |
| PM-G06 | **SLA público + status page** (status.campaignai.com.br). Cliente PME exige hoje. Sem SLA escrito, qualquer indisponibilidade vira processo no JEC. | Compliance/Comercial | M |
| PM-G07 | **Suporte ao cliente: canal definido, horário, SLA de resposta**. `Saldo.vue:181` usa número fallback fake — não há mesa de atendimento. Esperar que cliente WhatsApp para `5511999999999` é receita de NPS -50. | Suporte/Comercial | L |
| PM-G08 | **Documentação técnica para integradores** (API reference, OpenAPI, exemplos). Sem doc, parceiros que querem integrar vão chamar o suporte para cada endpoint. | Comercial | L |
| PM-G09 | **Termos de uso + Política de privacidade + DPA** revisados por advogado especialista em LGPD. Hoje a maioria dos SaaS BR usa template gratuito = passivo jurídico. | Legal/Compliance | M |
| PM-G10 | **PCI DSS scope review**. Cartão é tokenizado via MP (bom), mas precisa documentar SAQ-A formalmente. Se MP mudar fluxo, scope cresce. | Compliance | M |
| PM-G11 | **Pipeline CI com `composer audit` + `npm audit` + Snyk/Dependabot**. RT SEC-04-14.1 menciona, mas só como sugestão. Sem isso, pacote vulnerável entra em prod silenciosamente. | Segurança/DevOps | S |
| PM-G12 | **Plano de comunicação para clientes em caso de breach (LGPD art. 48 — 72h ANPD)**. Hoje não há template/processo para notificar ANPD/titulares. | Legal/Compliance | M |
| PM-G13 | **Onboarding manual para tenants iniciais (CSM)**. SaaS B2B novo precisa de high-touch nos primeiros 100 clientes — sem isso, churn brutal nas primeiras semanas. | Comercial | M |
| PM-G14 | **Smoke test de produção pós-deploy automático**. Mesmo com PM-G04, precisa de healthcheck que dispare campanha-de-teste para número interno e valide ponta a ponta. | Operacional/QA | M |
| PM-G15 | **Política de retenção de dados** documentada e implementada (audit_logs, webhook_deliveries, conversation_messages, logs). Hoje cresce até estourar disco. | LGPD/Operacional | M |

---

## 6. Sequenciamento Sugerido (Sprints de 1 semana)

### Sprint 1 — "Stop the bleeding" (semana 1)
Bloqueadores que envolvem fraude direta ou perda imediata de receita/segurança:
- **P0-01** Reescrever fluxo de cobrança: `reserve` ANTES do envio em `SendCampaignBatchJob`, `release` em falha. Adicionar `WithoutOverlapping($campaignId)` em `ProcessCampaignJob`.
- **P0-02** UNIQUE constraint `(campaign_id, contact_id)` + `(campaign_id, phone)` em `campaign_dispatches`. Lock em `sendNow`.
- **P0-05** UNIQUE em `balance_transactions(reference_type, reference_id, type)` + checagem por `mp_payment_id` em recharge.
- **P0-13** Validar host outbound webhook contra RFC1918/IMDS no FormRequest + re-resolver no POST.
- **P0-14** Remover `?? $request->query('secret')` do InfobipWhatsAppWebhookController.
- **P0-15** Remover `role`/`tenant_id` de `$fillable` em User; criar `assignRole()` dedicado.
- **P0-23** `assert > 0` em `BillingService::recharge`.
- **P0-37** `lockForUpdate` em `lockCampaignIfInsufficient`.
- **PM-G02** Setup Sentry + alerta de fila parada (em paralelo, com infra).

### Sprint 2 — Compliance LGPD (semana 2)
- **P0-06** Adicionar `OptOutService::isOptedOut` em `SendCampaignBatchJob`.
- **P0-07** UI de opt-out (`/settings/opt-outs` com lista + import/export).
- **P0-08** Token de unsubscribe em emails de campanha.
- **P0-09** Aplicar `QuietHoursService` no caminho de campanha.
- **P0-10** AuditLog em todos os eventos financeiros + migration triggers no MySQL para impedir UPDATE/DELETE em `audit_logs`.
- **P0-11** Tela `/settings/audit-log` com filtros.
- **P0-36** Policy de role no export CSV.
- **PM-G09** Início revisão jurídica (em paralelo).

### Sprint 3 — Cobrança e preço (semana 3)
- **P0-03** Fonte única de preço (API `GET /pricing` consumida por Plans, ConfirmSendModal, Step3Contacts).
- **P0-04** Snapshot `unit_cents_at_dispatch` em `Campaign` ou `CampaignDispatch`.
- **P0-12** Validar `settings.from_email` contra `EmailSenderDomain` no FormRequest.
- **P0-16** `unset($data['tenant_id'])` em CampaignsController.
- **P0-17** `where('tenant_id')` explícito em EmailDomainsController + trait `ChecksTenant`.
- **P0-18** Mudar resposta para 401 quando secret não configurado.
- **P0-19** Validar `type` e `request_id` em WebhookController MP.
- **P0-24** Remover `status: 'authorized'` literal; usar webhook para confirmar.
- **P0-27/P0-28** Máscara monetária + alinhar units API/UI.
- **P0-35** Corrigir `ReportController::credits` para usar enum real.

### Sprint 4 — UX crítica (semana 4)
- **P0-22** Implementar handlers de cancelar/redefinir/ver-dispatches.
- **P0-25** Tooltip explicando ∞ + flag de "uso interno" para admin.
- **P0-26** Banner de saldo baixo prepaid (compara com cost da campanha).
- **P0-30/P0-31** Adicionar WhatsApp em todos os filtros/badges.
- **P0-33** Auto-save expõe erro em toast.
- **P0-34** Criar rotas reais ou ocultar botões em SelectChannel.
- **P0-20** Deduplicação por `(messageId, status)` em webhook delivery.
- **P0-21** Validar `TenantChannel::isAvailable` no `store`.

### Sprint 5 — Atenções de alto valor + observabilidade (semana 5)
- **P1-03** Substituir `TenantContext` static por contextual (request scoped).
- **P1-04** Alinhar regex de telefone controller↔job.
- **P1-08** Allowlist de host para `audio_url`.
- **P1-10/P1-11** Rotação Sanctum + throttle email+ip.
- **P1-12/P1-13** Locks + UNIQUE em coupon_usages e subscriptions.
- **P1-14/P1-15** Migrar CSP para nonce + HTMLPurifier.
- **P1-19/P1-18** Cobrança IA por token + prompt-shield.
- **P1-22/P1-23** Otimização do ReportController + limite no export.
- **P1-27** Idempotência por `(campaign_id, batch_index)` para sent_count.
- **PM-G01** Runbook de incidentes (1-pager por cenário).
- **PM-G14** Smoke pós-deploy automático.

### Sprint 6 — Operação + Go-live readiness (semana 6)
- **PM-G03/PM-G04** Backup/restore testado + plano de rollback documentado.
- **PM-G05** Página /releases visível ao tenant.
- **PM-G06** Status page público.
- **PM-G07** Mesa de suporte (canal, horário, SLA) + treinamento dos atendentes.
- **PM-G08** OpenAPI publicada.
- **PM-G11** CI com composer/npm audit + Dependabot.
- **PM-G12** Template de notificação ANPD.
- **PM-G15** Job de retenção (audit_logs > 5 anos arquiva; webhook_deliveries > 90d apaga).
- **P2-19** Smoke E2E HappyPathTest na suite.
- Re-auditoria interna de 1 dia antes do go-live.

### Sprints 7-8 — Margem de segurança / P1 restantes
Sprints 7 e 8 absorvem P1 não-críticos restantes, melhorias prioritárias (P2-01..P2-23) e bugs descobertos durante o hardening.

**Total: 6-8 semanas com time de 3-4 devs + 1 QA + 1 UX/PM.**

---

## 7. Critérios de Go/No-Go para Lançamento

Lista objetiva — TODOS devem ser TRUE para autorizar lançamento:

1. ☐ Cobrança ocorre ANTES do envio físico (`reserve` no batch start, `release`/`refund` no failure path), comprovado por teste de integração matando worker no meio.
2. ☐ UNIQUE constraints aplicadas em `campaign_dispatches(campaign_id, contact_id)`, `campaign_dispatches(campaign_id, phone)`, `balance_transactions(reference_type, reference_id, type)`, `coupon_usages(coupon_id, tenant_id)`.
3. ☐ `WithoutOverlapping($campaignId)` em `ProcessCampaignJob` + `lockForUpdate` em `lockCampaignIfInsufficient`.
4. ☐ Caminho de campanha honra `OptOutService::isOptedOut` + `QuietHoursService` (cobertos por teste).
5. ☐ Tela `/settings/audit-log` viva + AuditLog em todos eventos financeiros + tabela append-only.
6. ☐ Preço exibido = preço cobrado em todas as telas (UI consome `GET /pricing`).
7. ☐ Snapshot de preço persistido em `Campaign` no momento do `sendNow`.
8. ☐ Outbound webhooks bloqueiam RFC1918/IMDS comprovado por pentest interno.
9. ☐ `User.fillable` sem `role`/`tenant_id`. Auditoria de mass-assignment em todos os models.
10. ☐ Secret de webhook só via header em todos os controllers.
11. ☐ `ReportController::credits` retorna valores corretos (teste com fixture).
12. ☐ Double-credit MP impossível (teste simula sincronia + webhook racing).
13. ☐ Smoke E2E `HappyPathTest` verde em CI.
14. ☐ Backup/restore exercitado em ambiente staging com sucesso.
15. ☐ Sentry + alerta de fila parada vivos em produção.
16. ☐ Runbook de incidentes (mínimo 5 cenários) revisado com time de suporte.
17. ☐ Termos de uso + Política de privacidade + DPA assinados por advogado.
18. ☐ Status page público funcional.
19. ☐ SLA escrito publicado.
20. ☐ Canal de suporte real configurado (não fallback 5511999999999).
21. ☐ Re-auditoria de segurança (pentest externo, 1 dia) sem CRITICA aberta.
22. ☐ Pipeline CI rodando `composer audit` + `npm audit` sem CVE high/critical.
23. ☐ Política de retenção de dados implementada (audit_logs, webhook_deliveries, logs).
24. ☐ Plano de notificação ANPD (template + processo) revisado.
25. ☐ Feature flag de "manutenção emergencial" testada (para parar disparos sem deploy).

---

## 8. Refinamentos do PM por Item

Onde refinei a proposta original do especialista:

- **P0-01 (Dev BLQ-06)** — Especialista propôs "reserve antes do envio". **Refinamento:** além de mover reserve para o batch start, adicionar **idempotency key client-side** (`Idempotency-Key` header em `/dispatch`) e **idempotency key server-side por `(campaign_id, batch_index)`** para que retry do Bus não dobre cobrança. Sem isso, P1-27 (sent_count duplicado em retry) volta como bug.

- **P0-02 (Dev BLQ-05+ATN-03)** — Dev sugeriu UNIQUE no DB. **Refinamento:** UNIQUE no DB **+** `WithoutOverlapping` na fila **+** lock pessimista no `sendNow` **+** disable do botão por debounce no frontend. Defesa em 4 camadas, porque uma única falha em runtime cobrir outras.

- **P0-03 (UX preço divergente)** — UX detectou divergência. **Refinamento:** não basta sincronizar — criar **endpoint público `GET /api/v1/pricing/effective?tenant_id=:id`** que retorne preço efetivo considerando overrides do tenant. Plans/ConfirmSendModal/Step3Contacts consomem essa única fonte. Bonus: snapshot desse retorno no momento do disparo (P0-04).

- **P0-05 (RT SEC-04-4.3)** — Red Team sugeriu UNIQUE em transactions. **Refinamento:** UNIQUE resolve double-insert mas não double-credit lógico. Adicionar também **`Payment::lockForUpdate()` antes de chamar `recharge`** e early-return se `Payment->already_credited_at IS NOT NULL`. Coluna `already_credited_at` torna a idempotência observável em auditoria.

- **P0-10 (Dev BLQ-19 mutabilidade)** — Sugerido "trigger SQL". **Refinamento:** combinar (a) trigger MySQL que aborta UPDATE/DELETE em `audit_logs`, (b) `protected $guarded = ['*']` + override de `save()` em Eloquent para usar `INSERT` direto via QueryBuilder, (c) hash chain (cada linha tem hash do anterior + payload) para detectar tampering retroativo.

- **P0-13 (RT SEC-04-8.1 SSRF)** — Red Team propôs allowlist e DNS rebinding protection. **Refinamento:** além disso, **proxy interno dedicado** (e.g., `http_proxy=internal-egress:3128` apenas para outbound webhooks) que aplica allow-list de IPs no nível de rede, não confiando só no app. Defesa em profundidade clássica.

- **P0-15 (RT SEC-04-2.2)** — Red Team disse "remover do fillable". **Refinamento:** além disso, escrever **observer** no User model que aborta `saving` se `isDirty('role')` e contexto não é "AdminPanel". Mesmo se um dev futuro adicionar de volta, o observer barra.

- **P0-22 (UX botões fantasma)** — UX listou os botões. **Refinamento:** "Cancelar agendamento" não é só um handler — exige fluxo de **`Bus::batch->cancel()` + release de saldo reservado proporcional ao não-enviado + AuditLog + notificação ao tenant**. Subestimar isso = entregar handler que cancela na UI mas mensagens continuam saindo. Esforço M, não S.

- **P0-35 (QA-REPORT-01)** — QA detectou o filtro errado. **Refinamento:** além de corrigir, **adicionar teste de fixture** que cria 1 transaction de cada tipo (reserve/release/recharge/manual_adjustment) e valida totais. Sem isso, próximo refactor reintroduz silenciosamente.

- **P0-37 (QA-CONC-01)** — QA pediu `lockForUpdate` em `lockCampaignIfInsufficient`. **Refinamento:** lock pessimista resolve, mas em produção alta concorrência prefiro **counter atômico (`UPDATE tenants SET balance_cents = balance_cents - X WHERE id=? AND balance_cents + credit_limit_cents >= X`)** com checagem de `affectedRows()`. Não-bloqueante, lock-free.

- **P1-19 (RT SEC-04-6.1 custo IA)** — Red Team sugeriu cobrar por token real. **Refinamento:** se Grok retornar usage no payload, OK; senão, estimar via **tiktoken** local + margem de 20% + reconciliação mensal com fatura do provider. Sem reconciliação, ainda há leakage residual.

---

## 9. Riscos Residuais

Mesmo após resolver tudo acima, restam:

1. **Dependência crítica de provider único (Infobip)** para SMS/Voice/Email/WhatsApp. Se Infobip cair, plataforma cai. Sem multi-provider fallback no roadmap. Mitigação: feature flag para alternar provider + contratar Twilio/Plivo como backup em 3-6 meses.
2. **MercadoPago como single point of failure de cobrança.** MP é confiável mas não tem 100% uptime. Considerar Stripe BR ou Pagar.me como alternativa para clientes enterprise.
3. **Custos variáveis de Grok IA** não totalmente previsíveis — mesmo cobrando por token, picos de uso (campanhas com IA em massa) podem comprimir margem. Monitorar P&L por tenant.
4. **Escalabilidade do worker `database`** — assim que tenants somados gerarem >10k jobs/min, MySQL como fila é gargalo. Migrar para Redis + Horizon antes do escalonamento.
5. **LGPD interpretação evolutiva** — ANPD ainda está calibrando o que é "consentimento válido" para opt-in em SMS marketing BR. Pode haver mudança regulatória forçando refactor em 6-12 meses.
6. **PCI compliance se MP mudar fluxo** — hoje SAQ-A (tokenização total). Se MP introduzir captura no app, scope sobe para SAQ-D = custo de compliance ~10x.
7. **Tenant malicioso enviando spam em massa** mesmo com opt-out implementado — KYC/onboarding precisa filtrar quem cria conta. Risco reputacional para o IP da plataforma.
8. **Ausência de SLO formal** com Infobip/MP para reclamar quando falham. Renegociar contratos com penalidades por uptime <99.9%.
9. **Crescimento desordenado de `audit_logs`** mesmo com retention — projeção: 1M linhas/mês em 100 tenants ativos. Particionar por mês antes de chegar a 50M.
10. **Risco humano de superadmin** — após resolver mass-assignment, ainda há acesso interno privilegiado sem 2FA obrigatório, sem logging de session, sem just-in-time access. Implementar PAM básico (2FA mandatório, audit trail de toda ação admin, expiração automática de privilégio).

---

**Fim do relatório consolidado.**
