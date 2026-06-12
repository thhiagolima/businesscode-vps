# Auditoria Completa Pre-Producao — BusinessCode SaaS

**Data:** 2026-04-01
**Plataforma:** Vue 3 + TypeScript (frontend) / Laravel 12 + PHP (backend)
**Escopo:** Multi-tenant SaaS para campanhas de marketing (SMS, Voice, Email, WhatsApp)
**Auditores:** QA Engineer, Dev Senior, Designer UI/UX, Arquiteto de Software, Gerente de Projeto

---

## Resumo Executivo

Foram encontrados **87 impeditivos de producao unicos** apos deduplicacao de 134 issues brutas dos 4 especialistas. A plataforma tem uma base tecnica razoavel mas apresenta **falhas criticas** em seguranca (IDOR, webhooks sem autenticacao, XSS), validacao de formularios (wizard permite avancar sem dados obrigatorios), UX (fluxo de voz desestruturado, sem confirmacao de envio), e arquitetura (race conditions em creditos, zero cobertura de testes em fluxos criticos).

| Severidade | Quantidade |
|------------|-----------|
| CRITICO    | 15        |
| ALTO       | 34        |
| MEDIO      | 38        |
| **TOTAL**  | **87**    |

---

## PARTE 1: ISSUES CRITICAS (15)

> Cada item abaixo IMPEDE o sistema de ir para producao. Devem ser corrigidos ANTES de qualquer deploy.

---

### C-01 — Registro de conta quebrado (token nao salvo)
- **Origem:** QA-035
- **Arquivo:** `frontend/src/pages/auth/Register.vue:121`
- **Problema:** Apos registro, `auth.setUser({ user: res.user, token: res.token })` seta o objeto inteiro como user em vez de separar user e token. O token nunca e salvo no store, `isAuthenticated` retorna false, e o usuario e redirecionado de volta ao login.
- **Impacto:** Nenhum usuario novo consegue entrar apos criar conta.
- **Correcao:** Separar: `auth.token = res.token; auth.setUser(res.user)` ou refatorar `setUser` para aceitar o objeto completo.

---

### C-02 — Wizard permite avancar sem conteudo (Steps 2 e 4)
- **Origem:** QA-001, QA-002, UX-003
- **Arquivo:** `frontend/src/pages/campaigns/Create.vue:180-185`
- **Problema:** `canAdvance` retorna `true` por default para Steps 2 (SMS/Email/Voice) e Step 4 (agendamento). Usuario pode criar e disparar campanha sem conteudo e sem data de agendamento valida.
- **Impacto:** Campanhas vazias podem ser disparadas, custando creditos sem entregar valor.
- **Correcao:** Adicionar validacoes:
  - Step 2 SMS/Email: `!!form.value.content`
  - Step 2 Voice: `!!form.value.audio_url`
  - Step 2 Email: `!!form.value.subject && !!form.value.content`
  - Step 4 schedule: verificar `payload.valid` emitido pelo Step4Schedule

---

### C-03 — Campanha de voz pode ser enviada sem audio
- **Origem:** QA-005, QA-006
- **Arquivos:** `frontend/.../VoiceStudio.vue`, `backend/app/Services/CampaignStateMachine.php:55-59`
- **Problema:** Backend aceita `content` (roteiro texto) como substituto de `audio_url` para campanhas voice. Frontend nao valida que audio foi gerado. Resultado: campanha de voz enviada com roteiro texto que nao pode ser reproduzido como torpedos de voz.
- **Impacto:** Campanhas de voz falham silenciosamente ou enviam conteudo inapropriado.
- **Correcao:** Backend: para type=voice, exigir `audio_url` nao vazio. Frontend: bloquear avanco no Step 2 ate audio ser gerado.

---

### C-04 — IDOR: Controllers sem verificacao de tenant
- **Origem:** DEV-001, DEV-002, DEV-012, DEV-013, DEV-014, ARQ-001
- **Arquivos:**
  - `CampaignsController.php:update()` — sem `ensureTenantOwns`
  - `ContactsController.php:update(), destroy()` — sem `ensureTenantOwns`
  - `ImportController.php:status()` — sem `ensureTenantOwns`
  - `ConversationController.php:show(), sendMessage(), updateStatus()` — sem trait `ChecksTenant`
  - `FunnelController.php:show(), update(), destroy()` — sem trait `ChecksTenant`
  - `AudioGenerationController.php:generate()` — sem `ensureTenantOwns`
- **Problema:** Esses controllers dependem exclusivamente do global scope para isolamento. Se o scope falhar (superadmin, job context, refactor), um tenant acessa dados de outro.
- **Impacto:** Vazamento de dados cross-tenant. Violacao de LGPD.
- **Correcao:** Adicionar `use ChecksTenant;` e chamar `$this->ensureTenantOwns($resource)` em TODOS os metodos que manipulam recursos por ID.

---

### C-05 — Webhook Infobip WhatsApp sem autenticacao
- **Origem:** DEV-004
- **Arquivo:** `backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php:25-37`
- **Problema:** O webhook aceita qualquer payload de qualquer IP sem validar secret/HMAC. Um atacante pode forjar mensagens inbound para qualquer tenant.
- **Impacto:** Injecao de mensagens falsas no sistema, potencial phishing via chatbot.
- **Correcao:** Implementar validacao HMAC ou secret header similar ao `WebhookController.validateSecret()`.

---

### C-06 — XSS via v-html no Step2WhatsApp
- **Origem:** DEV-006, QA-026
- **Arquivo:** `frontend/src/components/campaigns/steps/Step2WhatsApp.vue:36`
- **Problema:** `previewBodyHtml()` faz escape manual e depois re-injeta HTML via `.replace()`. Nao usa DOMPurify. Se templates WhatsApp ou valores de variaveis contiverem HTML malicioso, XSS e possivel.
- **Impacto:** Execucao de JavaScript malicioso no browser do usuario.
- **Correcao:** Aplicar `DOMPurify.sanitize()` no resultado final de `previewBodyHtml()`.

---

### C-07 — Bug: variavel $lock sobrescrita no scheduler
- **Origem:** DEV-003, ARQ-019
- **Arquivo:** `backend/app/Console/Commands/DispatchScheduledCampaigns.php:35,51`
- **Problema:** `$lock` (Cache::lock) e sobrescrita por `$lock` (retorno de lockCampaignIfInsufficient, que e array). O `forceRelease()` no finally nunca libera o cache lock real.
- **Impacto:** Campanhas agendadas podem ficar travadas por 60 segundos apos cada execucao.
- **Correcao:** Renomear segunda variavel para `$creditCheck`.

---

### C-08 — Race condition no sistema de creditos
- **Origem:** DEV-007, DEV-008, ARQ-002
- **Arquivos:** `backend/app/Services/Billing/CreditService.php:34,106`, `backend/app/Jobs/ProcessCampaignJob.php:168-186`
- **Problema:** (1) `reserve()` e `release()` sao stubs (apenas log). (2) Deducao ocorre APOS todos os envios, nao antes. (3) `max(0, balance - amount)` impede negativo mas nao rejeita quando saldo insuficiente. (4) Duas campanhas simultaneas podem ambas passar na verificacao.
- **Impacto:** Perda financeira — tenant consome mais creditos do que tem. Saldo incorreto no banco.
- **Correcao:** Implementar reserva atomica com `SELECT ... FOR UPDATE` antes do envio. Rejeitar quando saldo insuficiente em vez de clampar a zero.

---

### C-09 — VoiceStudio: fluxo desestruturado
- **Origem:** UX-002
- **Arquivo:** `frontend/src/components/campaigns/steps/VoiceStudio.vue`
- **Problema:** Briefing IA (6 campos), selecao de variacao, escrita manual, selecao de voz, preview, metricas, geracao de audio, player e historico — TUDO em uma unica tela com scroll longo dentro do Step 2. Nao ha separacao visual de etapas.
- **Impacto:** UX confusa, usuario nao entende o fluxo, abandono do wizard.
- **Correcao:** Dividir em 3 sub-steps: (1) Escrever/Gerar Roteiro, (2) Escolher Voz + Preview, (3) Gerar e Confirmar Audio. Usar tabs ou mini-stepper interno.

---

### C-10 — Stepper do wizard sem labels e inacessivel
- **Origem:** UX-001, UX-021
- **Arquivo:** `frontend/src/pages/campaigns/Create.vue:13-17`
- **Problema:** Steps mostram apenas numeros 1-5 sem labels textuais. Sem aria-labels, sem aria-current, sem diferenciacao visual entre completado/atual/futuro. Screen readers nao identificam etapas.
- **Impacto:** Todos os usuarios tem dificuldade de entender onde estao no fluxo. Inacessivel para usuarios com deficiencia visual.
- **Correcao:** Adicionar labels ("Nome", "Conteudo", "Contatos", "Agenda", "Enviar"), 3 estados visuais (completado/atual/futuro), aria-labels e aria-current.

---

### C-11 — Bug: ltrim no webhook secret validation
- **Origem:** DEV-016, ARQ-014
- **Arquivo:** `backend/app/Http/Controllers/API/V1/WebhookController.php:132`
- **Problema:** `ltrim($provided, 'Bearer ')` remove caracteres individuais (B,e,a,r,space), nao o prefixo "Bearer ". Se o secret comecar com esses caracteres, sera corrompido.
- **Impacto:** Webhooks legitimos podem ser rejeitados ou invalidos aceitos.
- **Correcao:** `str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided`

---

### C-12 — Exception handler vazio
- **Origem:** ARQ-007
- **Arquivo:** `backend/bootstrap/app.php:20-22`
- **Problema:** Handler de exceptions e um closure vazio. Se `APP_DEBUG=true` em producao, stack traces PHP sao expostas ao cliente.
- **Impacto:** Vazamento de paths, queries, credenciais em stack traces. Sem monitoramento de erros.
- **Correcao:** Implementar handler que renderiza erros genericos em producao. Integrar Sentry/Bugsnag. Garantir `APP_DEBUG=false`.

---

### C-13 — Closures inline nas rotas bloqueiam route:cache
- **Origem:** ARQ-005
- **Arquivo:** `backend/routes/api.php:133-161`
- **Problema:** Endpoints `GET /channels` e `GET /plans` usam closures com logica de banco/negocio inline. `php artisan route:cache` falha com closures.
- **Impacto:** Performance degradada em producao (rotas nao cacheaveis). Logica nao testavel.
- **Correcao:** Mover closures para controllers dedicados.

---

### C-14 — Cobertura de testes praticamente zero
- **Origem:** ARQ-003
- **Arquivo:** `backend/tests/`
- **Problema:** Apenas 4 testes Feature para WhatsApp. Zero testes para: auth, campanhas, creditos, tenant isolation, funnels, imports. Zero testes frontend.
- **Impacto:** Qualquer deploy pode introduzir regressoes. Impossivel refatorar com seguranca.
- **Correcao minima pre-producao:** Testes para CreditService, CampaignStateMachine, tenant isolation, fluxo de auth. Coverage minima: 40% nos fluxos criticos.

---

### C-15 — Editor de funis inutilizavel em mobile sem fallback
- **Origem:** UX-017
- **Arquivo:** `frontend/src/pages/funnels/Editor.vue`
- **Problema:** Layout fixo (420px de paineis laterais) sem media queries. Canvas VueFlow nao funciona em touch. Nenhum aviso ou fallback.
- **Impacto:** Pagina quebrada em mobile/tablet.
- **Correcao:** Adicionar aviso "Disponivel apenas em desktop" em telas < 1024px, ou implementar paineis collapsiveis.

---

## PARTE 2: ISSUES DE ALTA PRIORIDADE (34)

---

### A-01 — Envio sem modal de confirmacao
- **Origem:** QA-009, UX-005
- **Arquivo:** `Create.vue:340-358`
- **Problema:** `finalSaveAndSend()` dispara direto sem confirmacao. `ConfirmSendModal` existe mas NAO e usado.
- **Correcao:** Integrar ConfirmSendModal antes do envio final.

### A-02 — Step5Review mostra 0 contatos para listas
- **Origem:** QA-015
- **Arquivo:** `Create.vue:156`
- **Problema:** `contactsTotal` e `ref(0)` e nunca recebe valor. Review mostra "0 destinatarios, 0 creditos".
- **Correcao:** Step3Contacts deve emitir `update:contactsTotal` com `selectedList.contact_count`.

### A-03 — Step 4: payload.valid ignorado
- **Origem:** QA-008
- **Arquivo:** `Create.vue:onScheduleUpdate`
- **Problema:** Step4Schedule emite `valid: boolean` mas Create.vue ignora. Data passada pode ser agendada.
- **Correcao:** Usar `payload.valid` em `canAdvance` para Step 4.

### A-04 — Autosave race condition com nextStep
- **Origem:** QA-010, DEV-024
- **Arquivo:** `Create.vue:188-207`
- **Problema:** Debounce de 800ms pode disparar apos nextStep, sobrescrevendo dados com payload parcial.
- **Correcao:** Cancelar timer no `nextStep()` com `clearTimeout` + usar `AbortController` para requests pendentes.

### A-05 — Refresh no wizard perde progresso visual
- **Origem:** QA-007
- **Arquivo:** `Create.vue:394-428`
- **Problema:** `currentStep` volta para 1 no refresh. Dados estao salvos mas usuario nao sabe.
- **Correcao:** Persistir `currentStep` em sessionStorage ou recalcular com base nos dados carregados.

### A-06 — LIKE injection em busca (3 controllers)
- **Origem:** DEV-005, DEV-009, DEV-010
- **Arquivos:** `ConversationController.php:27`, `ReportController.php:47`, `Admin/TenantsController.php:29`
- **Correcao:** Sanitizar `%` e `_` com `str_replace(['%', '_'], ['\\%', '\\_'], $search)`.

### A-07 — CSV import: formula injection so checa primeira linha
- **Origem:** DEV-011, ARQ-029
- **Arquivo:** `ImportController.php:28-31`
- **Correcao:** Verificar/sanitizar todas as celulas no ImportContactsJob.

### A-08 — Creditos debitados quando IA retorna erro
- **Origem:** DEV-030
- **Arquivo:** `backend/app/Services/Ai/ChatAiService.php:86-94`
- **Correcao:** Verificar `$reply` nao vazio antes de debitar.

### A-09 — Tenant destroy sem cascade
- **Origem:** DEV-022
- **Arquivo:** `Admin/TenantsController.php:131-136`
- **Correcao:** Implementar cascade explicito ou bloquear delete se tenant tem dados.

### A-10 — Plan destroy sem verificar tenants vinculados
- **Origem:** DEV-023
- **Arquivo:** `Admin/PlansController.php:61-66`
- **Correcao:** Verificar se existem tenants usando o plano.

### A-11 — Registro nao e atomico (tenant + user)
- **Origem:** DEV-031
- **Arquivo:** `AuthController.php:22-49`
- **Correcao:** Envolver em `DB::transaction()`.

### A-12 — ReDoS no FunnelEngine (regex do usuario)
- **Origem:** DEV-015, ARQ-006
- **Arquivo:** `FunnelController.php:373`, `FunnelEngineService.php:244`
- **Correcao:** Usar `preg_quote()` ou limitar a keywords literais. Se regex necessario, reduzir `pcre.backtrack_limit`.

### A-13 — Sem rate limiting em envio de mensagens WhatsApp
- **Origem:** DEV-018
- **Arquivo:** `routes/api.php:123`
- **Correcao:** Adicionar `throttle:30,1`.

### A-14 — ProcessCampaignJob: tudo em um unico job
- **Origem:** ARQ-004, ARQ-009
- **Arquivo:** `ProcessCampaignJob.php`
- **Correcao:** Adotar Job Batching: criar sub-jobs por contato/bloco. Pre-carregar dispatches existentes em Set.

### A-15 — Global scope falha em contexto de jobs/commands
- **Origem:** ARQ-010
- **Arquivo:** `backend/app/Models/Traits/AppliesTenantScope.php:11-17`
- **Correcao:** Implementar `TenantContext::set($tenantId)` para jobs/commands.

### A-16 — Queue database nao escala
- **Origem:** ARQ-011
- **Arquivo:** `backend/config/queue.php`
- **Correcao:** Migrar para Redis, configurar Horizon.

### A-17 — Token Sanctum em localStorage + CSP fraco
- **Origem:** ARQ-017, ARQ-018
- **Arquivos:** `frontend/src/stores/auth.ts:75`, `backend/.../SecurityHeaders.php:19`
- **Correcao:** Migrar para SPA cookies (Sanctum suporta) ou remover `unsafe-inline`/`unsafe-eval` do CSP.

### A-18 — Axios interceptor pode duplicar/perder
- **Origem:** ARQ-016
- **Arquivo:** `frontend/src/composables/useApi.ts:18-49`
- **Correcao:** Configurar interceptors uma unica vez em main.ts como singleton.

### A-19 — Export de relatorio nao funciona (401)
- **Origem:** ARQ-021
- **Arquivo:** `frontend/src/stores/report.ts:68`
- **Correcao:** Usar axios com responseType 'blob' ou signed URL.

### A-20 — Chatbot persona: formulario sem validacao frontend
- **Origem:** QA-011
- **Arquivo:** `frontend/src/pages/chatbot/Settings.vue:59`
- **Correcao:** Adicionar validacao em todos os campos obrigatorios antes de submit.

### A-21 — Profile: nome/email/senha sem validacao frontend
- **Origem:** QA-012, QA-013
- **Arquivo:** `frontend/src/pages/profile/Index.vue`
- **Correcao:** Adicionar `required`, validacao de formato email, validacao de match de senha.

### A-22 — Registro: requisitos de senha nao informados
- **Origem:** QA-014
- **Arquivo:** `frontend/src/pages/auth/Register.vue`
- **Correcao:** Mostrar requisitos (min 8 chars, maiuscula, numero) e validar inline.

### A-23 — goToStep pula validacao de steps intermediarios
- **Origem:** QA-020
- **Arquivo:** `Create.vue:276-278`
- **Correcao:** Re-validar steps intermediarios ao navegar para frente.

### A-24 — Auth store nao persiste user (refresh com API offline quebra)
- **Origem:** QA-021
- **Arquivo:** `frontend/src/stores/auth.ts:72-78`
- **Correcao:** Persistir `user` alem de `token`, ou tratar falha do `/auth/me` gracefully.

### A-25 — Conversas: icone WhatsApp misleading
- **Origem:** UX-006
- **Arquivo:** `AppSidebar.vue`
- **Correcao:** Trocar `ti-brand-whatsapp` por `ti-messages`. Considerar agrupar Conversas + Chatbot.

### A-26 — Empty states inconsistentes (Reports, Credits, Detail)
- **Origem:** UX-014, UX-015
- **Arquivos:** `Reports/Campaigns.vue`, `Reports/Credits.vue`, `campaigns/Detail.vue`
- **Correcao:** Usar componente `EmptyState` em todas as tabelas vazias.

### A-27 — Sem retry visual em falhas de IA/audio
- **Origem:** UX-016
- **Arquivos:** `Create.vue`, `VoiceStudio.vue`, `AiMode.vue`
- **Correcao:** Adicionar estado de erro com botao "Tentar novamente" apos falhas.

### A-28 — Wizard responsividade: botoes transbordam em mobile
- **Origem:** UX-018
- **Arquivo:** `Create.vue:96-117`
- **Correcao:** `flex-wrap` + media query para empilhar botoes.

### A-29 — SelectChannel: cards inacessiveis via teclado
- **Origem:** UX-022
- **Arquivo:** `SelectChannel.vue`
- **Correcao:** Adicionar `role="button" tabindex="0" @keydown.enter`.

### A-30 — Tela de sucesso apos envio inexistente
- **Origem:** UX-025
- **Arquivo:** `Create.vue:340-379`
- **Correcao:** Criar tela intermediaria "Campanha enviada!" com resumo e proximos passos.

### A-31 — Adhoc phones sem validacao de formato
- **Origem:** QA-029
- **Arquivo:** `CampaignsController.php:77`
- **Correcao:** Adicionar rule `E164Phone` a `settings.adhoc_phones.*`.

### A-32 — Webhook logs expoe dados pessoais (LGPD)
- **Origem:** DEV-032
- **Arquivos:** `WebhookController.php:31`, `WhatsAppWebhookController.php:46`
- **Correcao:** Logar apenas metadata (message_id, status), nao payload completo.

### A-33 — Brute-force protection duplicada e fragil
- **Origem:** ARQ-013
- **Arquivo:** `AuthController.php:75-88`
- **Correcao:** Remover logica manual de cache e confiar no middleware `throttle`.

### A-34 — FunnelEngine: last_message_id race condition
- **Origem:** ARQ-031
- **Arquivo:** `FunnelEngineService.php:330`
- **Correcao:** Capturar `$msg->id` do modelo criado em vez de `max('id')`.

---

## PARTE 3: ISSUES DE MEDIA PRIORIDADE (38)

| ID | Descricao | Arquivo Principal |
|----|-----------|-------------------|
| M-01 | Polling `visibilitychange` sem cleanup | `useConversationPolling.ts` |
| M-02 | TypeScript fraco: `any` extensivo nos stores | `report.ts`, `ai.ts`, `admin.ts` |
| M-03 | DashboardController N+1 sem cache | `DashboardController.php` |
| M-04 | ContactList.index() sem paginacao | `ContactListsController.php` |
| M-05 | CSP `unsafe-inline`/`unsafe-eval` | `SecurityHeaders.php` |
| M-06 | Sem circuit breaker para servicos externos | `InfobipService`, `GrokService`, `ElevenLabsService` |
| M-07 | Password reset sem rate limiting | `routes/api.php:37` |
| M-08 | Funnel import aceita node types invalidos | `FunnelController.php:310` |
| M-09 | ProcessCampaignJob adhoc dispatch sem idempotencia | `ProcessCampaignJob.php:361` |
| M-10 | Autosave `scheduled_at` sem `after:now` | `CampaignsController.php:106` |
| M-11 | Step5Review: creditos insuficientes nao bloqueia envio | `Step5Review.vue` |
| M-12 | CSV parser frontend nao normaliza telefones | `Step3Contacts.vue:312` |
| M-13 | Lista de contatos: cache local de count pode ficar stale | `Step3Contacts.vue:229` |
| M-14 | WhatsApp variaveis nao mapeadas sem mensagem explicativa | `Step2WhatsApp.vue:139` |
| M-15 | API key Infobip pode ser apagada acidentalmente | `SettingsInfobip.vue:86` |
| M-16 | Settings Infobip sem validacao | `SettingsInfobip.vue` |
| M-17 | `isLoading` compartilhado em todo o wizard | `Create.vue:112` |
| M-18 | Campo nome: feedback visual sem mensagem de erro | `Create.vue:33` |
| M-19 | Reset password: sem redirect quando faltam params | `ResetPassword.vue:103` |
| M-20 | ConfirmSendModal nao suporta WhatsApp type | `ConfirmSendModal.vue:74` |
| M-21 | ManualMode emite a cada keystroke (alto volume PUT) | `ManualMode.vue:82` |
| M-22 | `estimated_contacts` manipulavel via API | `CampaignsController.php:65` |
| M-23 | Funnel editor sem validacao (save/activate sem nodes) | `Editor.vue` |
| M-24 | Relatorios sem submenu no sidebar | `router/index.ts` |
| M-25 | Catch-all redireciona para login em vez de 404 | `router/index.ts:170` |
| M-26 | Perfil usa layout antigo (page-header duplicado) | `profile/Index.vue` |
| M-27 | Pagina IA usa layout antigo | `ai/Index.vue` |
| M-28 | Reports usam badges manuais em vez de StatusBadge | `Reports/Campaigns.vue` |
| M-29 | Filtro de canal nao inclui WhatsApp | `Campaigns/Index.vue:27` |
| M-30 | SelectChannel: todos os "Configurar" apontam para WhatsApp | `SelectChannel.vue:45` |
| M-31 | Dashboard CTA empty state aponta para URL errada | `dashboard/Index.vue:119` |
| M-32 | Campaign Detail: botao "Ver relatorio" sem handler | `Detail.vue:13` |
| M-33 | Campaign Detail: dispatches sem skeleton/empty/paginacao | `Detail.vue:100` |
| M-34 | Plans upgrade via mailto (antipattern) | `settings/Plans.vue:61` |
| M-35 | Chatbot persona sem rascunho/auto-save | `chatbot/Settings.vue` |
| M-36 | Funnels/Contacts: `confirm()` nativo em vez de ConfirmModal | `funnels/Index.vue:146` |
| M-37 | "Analisar com IA" nao exibe resultado na UI | `ManualMode.vue:86` |
| M-38 | Admin store mistura dominios com isLoading compartilhado | `stores/admin.ts` |

---

## PARTE 4: GAPS IDENTIFICADOS PELO GERENTE DE PROJETO

Alem dos issues encontrados pelos 4 especialistas, a re-auditoria cruzada identificou:

### GP-01 — Sem pagina de Terms/Privacy real
As rotas `/terms` e `/privacy` existem no router mas nao foram auditadas. Se forem paginas placeholder, e um bloqueador legal.

### GP-02 — Sem email transacional configurado
O sistema envia emails de campanha via Infobip, mas password reset e emails de sistema usam o mailer padrao do Laravel. Se `MAIL_*` nao estiver configurado, password reset nao funciona.

### GP-03 — Sem logout em outros dispositivos
Nao ha mecanismo de "logout all sessions" nem invalidacao de tokens antigos quando senha e alterada.

### GP-04 — Sem rate limiting no autosave
O endpoint `PUT /campaigns/{id}` e chamado a cada 800ms durante edicao. Sem throttle especifico, um usuario pode sobrecarregar o servidor editando rapidamente.

### GP-05 — Sem politica de retencao de logs/audit
`audit_logs` e `credit_transactions` crescem indefinidamente. Sem politica de retencao ou archival.

### GP-06 — Sem health check alem do `/up` padrao
Nao ha health check que verifica DB, Redis, queue depth, e status de APIs externas.

---

## PARTE 5: PLANO DE CORRECAO RECOMENDADO

### Sprint 0 — Bloqueadores Absolutos (ANTES do deploy)
1. **C-01** Corrigir registro de conta
2. **C-02** Validacoes do wizard (steps 2, 4)
3. **C-03** Exigir audio_url para campanhas voice
4. **C-04** Adicionar ensureTenantOwns em TODOS os controllers
5. **C-05** Autenticar webhook Infobip WhatsApp
6. **C-06** DOMPurify no Step2WhatsApp
7. **C-07** Renomear variavel $lock
8. **C-08** Implementar reserva atomica de creditos
9. **C-09** Reestruturar VoiceStudio em sub-steps
10. **C-10** Labels e acessibilidade no stepper
11. **C-11** Corrigir ltrim no webhook
12. **C-12** Exception handler + APP_DEBUG=false
13. **C-13** Mover closures para controllers
14. **C-14** Testes minimos para fluxos criticos
15. **C-15** Fallback mobile no editor de funis
16. **A-01** Modal de confirmacao de envio
17. **A-02** Corrigir contactsTotal no Step5Review
18. **A-03** Usar payload.valid do Step4Schedule
19. **A-11** Registro atomico com DB::transaction

### Sprint 1 — Alta Prioridade (primeira semana pos-deploy)
- A-04 a A-10 (autosave, refresh, LIKE injection, CSV, AI credits, cascades)
- A-14 (job batching)
- A-17 (token em cookie + CSP)
- A-19 (export de relatorio)
- A-30 (tela de sucesso)
- A-31 (validacao E164)
- A-32 (LGPD logs)

### Sprint 2 — Media Prioridade (semanas 2-3)
- Todos os M-01 a M-38
- GP-01 a GP-06

---

## METRICAS DO AUDIT

| Especialista | Issues Brutas | Issues Unicas | Sobreposicoes |
|-------------|--------------|---------------|---------------|
| QA Engineer | 37 | 27 | 10 |
| Dev Senior | 32 | 20 | 12 |
| Designer UI/UX | 33 | 28 | 5 |
| Arquiteto SW | 32 | 19 | 13 |
| Gerente Projeto | 6 | 6 | 0 |
| **Total Bruto** | **140** | — | — |
| **Total Deduplicado** | — | **87 + 6 GP** | — |

### Arquivos Mais Criticos (por quantidade de issues)
1. `frontend/src/pages/campaigns/Create.vue` — 14 issues
2. `backend/app/Http/Controllers/API/V1/CampaignsController.php` — 8 issues
3. `frontend/src/components/campaigns/steps/VoiceStudio.vue` — 4 issues
4. `backend/app/Jobs/ProcessCampaignJob.php` — 5 issues
5. `backend/app/Services/Billing/CreditService.php` — 4 issues
6. `backend/routes/api.php` — 4 issues
7. `frontend/src/stores/auth.ts` — 3 issues

---

**Conclusao:** O sistema NAO esta pronto para producao. Ha 15 issues criticas que devem ser resolvidas antes de qualquer deploy, 34 de alta prioridade para a primeira semana, e 44 de media prioridade para as semanas seguintes. A Sprint 0 (bloqueadores) e estimada em 3-5 dias de trabalho intensivo com foco em seguranca, validacao e UX.
