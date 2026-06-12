# Dev Sr Express Re-audit — Commit 096c5c68
**Data:** 2026-05-29
**Auditor:** Dev Sênior Full-Stack (independente)
**Escopo:** 26 arquivos / 1.789 inserções do commit `fix(security/billing): close 9 P0R blockers`.

---

## 1. Status P0R

| ID | Status | Evidência (arquivo:linha) | Justificativa |
|---|---|---|---|
| **P0R-01** UNIQUE balance_transactions | ✅ Fechado | `backend/database/migrations/2026_05_29_000002_add_unique_indexes_to_balance_transactions_and_dispatches.php:27-34` cria `bt_idempotency_unique` em `(tenant_id, type, reference_type, reference_id)`. Teste: `backend/tests/Feature/Billing/BalanceTransactionUniqueTest.php:33` espera `UniqueConstraintViolationException` na 2ª inserção com mesma chave. Passa isolado (3/3). | Constraint composta criada e testada. Defesa em profundidade real. |
| **P0R-02** UNIQUE campaign_dispatches | ✅ Fechado | Mesma migration `:36-42` cria `cd_campaign_contact_unique` e `cd_campaign_phone_unique`. Teste: `backend/tests/Feature/CampaignDispatchUniqueTest.php:35-98` cobre (campaign,contact) e (campaign,phone) duplicados e cross-campaign. Passa isolado. | Confirmado. Nota: como o índice phone permite múltiplos NULL no MySQL, o caminho contact_list e adhoc estão cobertos sem colisão entre si. |
| **P0R-03** MigrationRoundtripTest contamina DB | ❌ **REABERTO** | `backend/tests/Feature/Billing/MigrationRoundtripTest.php:30-38` adicionou `tearDown` chamando `migrate:fresh`, MAS `php artisan test` continua reportando **369 failed / 29 passed** e `phpunit` puro oscila entre **27 e 84 erros** entre execuções (não-determinístico). Vide §2. | O `migrate:fresh --force` no tearDown **comita DDL fora da transação do `RefreshDatabase`** dos testes seguintes, deixando o sqlconnection com transação inválida. O problema NÃO foi resolvido — apenas mudou de "rollback parcial" para "drop+migrate fora de scope". Fix correto seria `@group migration` + excluir da default suite (como o próprio consolidado-reaudit recomendava). |
| **P0R-04** Sanctum 24h + rotação | ✅ Fechado | `backend/config/sanctum.php:55-57` default 1440 min; bypass via `SANCTUM_EXPIRATION_MINUTES=0`. `backend/app/Http/Controllers/API/V1/AuthController.php:202` chama `$user->tokens()->delete()` em `changePassword`. Testes: `backend/tests/Feature/AuthChangePasswordRotationTest.php:32-44` valida 0 tokens após troca; `backend/tests/Feature/Auth/SanctumExpirationConfigTest.php:28,57` valida 24h. | Implementação correta. `resetPassword` também já revoga tokens (linha 267). Auditoria com `tokens_revoked=true` (linha 204). |
| **P0R-05** SSRF DNS rebinding TOCTOU | ✅ Fechado | `backend/app/Services/Security/OutboundWebhookGuard.php:30-52` `pinnedResolution()` retorna `[host,port,ip]` validado. `backend/app/Jobs/FireOutboundWebhookJob.php:58-68` usa `CURLOPT_RESOLVE` + `withoutRedirecting()` para fechar a janela TOCTOU e bloquear 302→IMDS. | Defesa correta. `withoutRedirecting()` também adicionado — vide §3 sobre regressão. |
| **P0R-06** rate-limit AuditLog | ✅ Fechado | `backend/routes/api.php:134-135` `throttle:30,1` aplicado. `backend/app/Http/Controllers/API/V1/AuditLogController.php:27` exige `min:3, max:60` no `action` antes do LIKE; `:40` escapa `% _` antes do `like`. | Throttle + validação correta. Mitigação contra blind-enumeration e DoS por LIKE longo aplicada. Nota: não há índice composto novo em `audit_logs(tenant_id, action, created_at)` (consolidado recomendava — não bloqueador, P1). |
| **P0R-07** refund em transação | ✅ Fechado | `backend/app/Jobs/ProcessCampaignJob.php:163-193 (then)` e `:212-237 (catch)`: ambos `DB::transaction` + `lockForUpdate` + `refund_done` flag em `settings`. Idempotente por checagem `if (! empty($snapshot['refund_done']))`. | Implementação correta. Race entre `then`+`catch`+`cancel` agora protegida por lock pessimista + flag. |
| **P0R-08** AuditLog ampliado | ✅ Fechado | `PaymentController.php:202,229,273,331` (pix_created, boleto_created, rejected, approved/pending); `SubscriptionController.php:152,167,220` (created, creation_failed, cancelled); `MercadoPagoService.php:196,222` (payment.webhook_processed, subscription.webhook_status_change); `CampaignsController.php:204` (campaign.scheduled). | Cobertura ampla. **Lacuna**: `WebhooksController::mercadopago` (ingestão crua antes do switch case) não tem `AuditLog::record`. O ingest é importante p/ SOC2 — mas `MercadoPagoService` registra no processamento, o que mitiga. P1, não bloqueador. |
| **P0R-09** CSV escape + cap | ✅ Fechado | `OptOutsController.php:90` `MAX_IMPORT_LINES = 50_000` (consolidado pedia 5k — diferente mas defensável); `:130-133` aborta + 422 quando excede; `:97-104` `safeCsvCell()` prefixa `'` em `=+-@\t\r`; usado em `:209-213` no export. | Implementação correta. Cap de 50k é generoso mas razoável (5MB de upload já limita pelo `max:5120`). |

**Sumário:** ✅ 8 fechados / ❌ 1 reaberto (P0R-03) / ⚠️ 0 parciais.

---

## 2. Suíte

| Comando | Resultado | Esperado pelo commit |
|---|---|---|
| `php artisan test` | **369 failed / 29 passed (1 warning)** em 92.99s | 398/398 passed |
| `./vendor/bin/phpunit` (puro) | **399 tests / Errors: 27 a 84 / Failures: 1 a 2** (não-determinístico entre runs) | 399/399 passed |
| `phpunit tests/Feature/Billing/BalanceTransactionUniqueTest.php` (isolado) | ✅ 3/3 OK | — |
| `phpunit tests/Feature/CampaignDispatchUniqueTest.php` (isolado) | ✅ passou em conjunto Messaging+Billing (105/105) | — |
| `phpunit tests/Feature/AuthChangePasswordRotationTest.php` (isolado) | ✅ passou em Auth (4/4) | — |
| `phpunit --testsuite=Pentest` (isolado) | ✅ 42/42 OK | — |
| `phpunit --testsuite=Feature` (após `migrate:fresh`) | ❌ 271 tests, 247 errors, 4 failures | — |

**Conclusão:** os testes novos passam **isoladamente**, mas a contaminação do `MigrationRoundtripTest::tearDown` (`migrate:fresh --force`) destrói a transação do `RefreshDatabase` de **todos** os testes subsequentes da mesma suíte/processo. A claim do commit ("Tests: 398 passing in `php artisan test`; 399 passing in `phpunit` alone") **NÃO se reproduz** — minha máquina dá 369 failed / 29 passed em `php artisan test` e 27-84 errors em phpunit puro.

---

## 3. Regressões / Riscos novos detectados

### Bloqueador
1. **P0R-03 reaberto** — `tearDown` com `migrate:fresh` causou **regressão maior** do que o problema original. Antes era contaminação por DDL parcial; agora é destruição completa da transação de RefreshDatabase. CI não passa.

### Atenção
2. **`Http::withoutRedirecting()` em `FireOutboundWebhookJob`** (`:62`) — bloqueia 302/301 legítimos. Webhooks de serviços que normalizam path (Slack/Discord costumam redirect 301 para HTTPS canônico, GitHub usa 302 em alguns endpoints) vão falhar silenciosamente. Sugestão: permitir 1 redirect com revalidação do destino pelo Guard, ou documentar que webhooks DEVEM apontar para URL final.
3. **`tokens()->delete()` em `changePassword`** — revoga TODOS os tokens, inclusive os `api-tokens` server-to-server criados via `ApiTokensController`. Cliente que tem integração API (Zapier, n8n) verá tokens revogados sem aviso ao trocar senha do usuário dono. Sugestão: filtrar por `name != 'api'` ou expor flag `revoke_api_tokens`.
4. **`migrate:fresh` no tearDown é lento em CI** (~30s/teste de roundtrip + reset de todo o DB). Pior solução possível para o problema. Deveria ser `@group migration` excluído da suite default.
5. **WebhookController::mercadopago ingestão raw** continua sem `AuditLog::record` na entrada (apenas processamento via `MercadoPagoService`). Em incidente de webhook duplicado/replay, sem trilha de "recebido em T, processado em T+δ". P1.
6. **`UniqueConstraintViolationException` não tratada** — `BalanceTransaction::create` no `BillingService::recharge`/`reserve`/`release` passa a estourar exception ao invés do silencioso "skip" anterior. Se algum caller esperava idempotência silenciosa, agora recebe 500. Verificar `MercadoPagoService::activatePayment` e webhook handlers.

### Melhoria
7. `MAX_IMPORT_LINES = 50_000` é confortável para 5MB de CSV, mas em produção um arquivo com identificadores curtos pode passar muito disso. Considerar cap por bytes além do cap por linhas.
8. Sem teste de regressão para `MigrationRoundtripTest::tearDown` em si — se algum dev mudar a tearDown amanhã, ninguém pega.

---

## 4. Veredito Dev

**NÃO PODE LANÇAR.**

8 dos 9 P0R foram fechados com qualidade boa (código + testes), mas o **P0R-03 piorou em vez de melhorar**: o `tearDown` chamando `migrate:fresh` causou uma regressão de suíte completa, com `php artisan test` reportando 369 failed / 29 passed e `phpunit` puro reportando 27-84 erros não-determinísticos. A claim do commit de "398/398 e 399/399 verdes" **não se reproduz na minha máquina**. Sem CI verde não há rede de regressão para os próximos PRs, e como o consolidado já alertou, qualquer hotfix entra em produção cego. Adicionalmente, `withoutRedirecting()` é fix agressivo demais (quebra webhooks legítimos com 301/302) e `tokens()->delete()` revoga indiscriminadamente tokens server-to-server. **Próximo passo:** marcar `MigrationRoundtripTest` com `@group migration` e excluí-lo da default suite via `--exclude-group=migration` no CI default; rodar como job dedicado. Sem isso, lançar é cego.
