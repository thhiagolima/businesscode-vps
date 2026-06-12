# Relatório UX/UI Sênior — Fluxo de Campanhas
**Data:** 2026-05-28
**Auditor:** Designer Sênior UX/UI
**Escopo:** Fluxo completo de campanhas (SMS, Voz, Email, WhatsApp) + cobrança + auditoria + relatórios

> Referência cruzada: a auditoria antiga (`audit/01-ux-ui.md`) já apontava problemas em criação de campanha e billing. Vários itens críticos **persistem** e novos foram introduzidos pelo canal WhatsApp.

---

## Sumário Executivo

- **NÃO pode lançar.** Foram encontrados **14 bloqueadores** que comprometem a confiabilidade do cobrança/UX em produção.
- **Risco #1 — Preço de envio inconsistente em 3 lugares:** `Plans.vue` anuncia "SMS R$ 0,10 · Voz R$ 0,30 · WhatsApp R$ 0,40", `ConfirmSendModal.vue` calcula "SMS=1c, Voz=5c, Email=2c, WhatsApp=3c" e `Step3Contacts.vue` usa "SMS=1, Voz=5, Email=2, WhatsApp=3 créditos". O cliente vê 3 valores diferentes para o mesmo envio. **Falha total de transparência de cobrança.**
- **Risco #2 — Botões fantasma:** "Cancelar" agendamento, "Redefinir" failed (`Detail.vue:17,19`) e "Ver todos os dispatches" (`reports/CampaignDetail.vue:134`) não têm handler — clicar não faz nada.
- **Risco #3 — Auditoria invisível:** Não existe tela/aba "Histórico" nem "Log" para o tenant ver quem alterou o quê, quando, e quanto foi debitado. Compliance/LGPD comprometida.
- **Risco #4 — Canal WhatsApp incoerente:** `campaigns/Index.vue` tem filtro WhatsApp mas `channelMap` não inclui WhatsApp; relatórios sequer expõem WhatsApp como opção; `PhonePreview` não suporta WhatsApp; existem 4 canais no produto mas a UI consistentemente esquece um deles.
- **Risco #5 — Saldo "infinito":** `Create.vue:143` e `Step5Review.vue:90` exibem `∞` quando `balance_cents === -1`. Cliente comum não entende o glifo e não há tooltip; admins com crédito ilimitado podem disparar sem feedback de custo real.

---

## 1. Criação de Campanha

### 🔴 Bloqueadores

- **`Create.vue:209-232` — Modal de confirmação não é modal real.** O `<div v-if="showConfirmSend" class="modal modal-blur fade show d-block">` é renderizado inline sem `<teleport>`, sem trap de foco, sem `aria-modal`, sem fechar com `Esc`, e sem backdrop nativo do Bootstrap. Em telas longas pode ficar atrás do scroll. WCAG 2.1 falha.
- **`Create.vue:307-319` — Validação fraca no Step 1.** `canAdvance` só checa `name` para criar a campanha; ao retornar não valida que o template/áudio/conteúdo está completo no servidor antes de avançar. Resultado: o usuário pode avançar com campanha "criada" mas com `content=null` e descobrir o problema só no Step 3 (review).
- **`Create.vue:336-340` — Auto-save silencioso engole erros.** `catch { /* silent — will retry on next change */ }`. Se o servidor retornar 422/500 o cliente não vê nada, acredita que está "Salvo" (line 28) e pode perder horas de trabalho.
- **`Step3Contacts.vue:190` — Preço por envio hardcoded no frontend.** `const unitRates = { sms: 1, voice: 5, email: 2, whatsapp: 3 }` é a fonte da verdade exibida ao cliente. Backend tem preços dinâmicos (vide `admin/billing/Pricing.vue`). Cliente verá um valor, será cobrado outro.
- **`Step2WhatsApp.vue:10-14` — Empty state sem call-to-action navegável.** "Configure o WhatsApp no painel Admin e sincronize os templates" não tem botão/link — usuário fica preso. Tenants sem permissão de admin não têm como agir.
- **`SelectChannel.vue:151-159` — `configUrl()` retorna rotas inexistentes.** `/settings/email`, `/settings/sms`, `/settings/voice` não existem no router (`router/index.ts:198-216` só tem `/settings/whatsapp`, `/settings/email-domains`). Botão "Configurar" em canais pending leva a 404.

### 🟡 Pontos de Atenção

- **`Create.vue:147-152` — Botões da sidebar do Step 2 só aparecem na coluna direita.** Em telas mobile o "Resumo da Campanha" + "Revisar Campanha" rola abaixo de tudo; usuário não tem CTA flutuante no Step 2 mobile.
- **`Create.vue:545-550` — `route.query.channel` aceito sem validação semântica.** Se o usuário trocar manualmente para um canal que o tenant não tem habilitado, ele entra no fluxo e só vai falhar no envio.
- **`Step4Schedule.vue:55` — Fuso fixo em "Brasília GMT-3"** ignora horário de verão e usuários em outros fusos. Não há picker de timezone.
- **`Step5Review.vue:73-98` — Cost card mostra "Saldo após envio" indiretamente.** Existe `Total` e `Saldo` mas não há linha explícita de "Saldo restante após o disparo", apesar de `ConfirmSendModal` ter essa linha. Inconsistência entre a tela de review e o modal de confirmação.
- **`Step3Contacts.vue:212-217` — Regex de telefone permite 7 dígitos.** `^\+?[1-9]\d{6,14}$` aceita +1234567 como válido. E.164 mínimo prático é 8 dígitos. Cliente importa números falsos achando que estão ok.
- **`Step2Content.vue:13` — Toggle IA/Manual oculto para voz, mas `VoiceStudio` (linha 4-17) tem o próprio toggle — duplicação de UI conceitual.** Confuso para quem pula entre canais.
- **`Create.vue:269,276` — `currentStepMax` começa em 1 e nunca volta atrás após perder validação.** Se o usuário deletar a lista de contatos no Step 2 e voltar ao Step 1, o "step 3" continua clicável (pílulas), mas vai dar erro silencioso.

### 🟢 Melhorias Propostas

- Adicionar **indicador de progresso de auto-save** com timestamp ("Salvo às 14:32"), não só ícone de check.
- **Atalhos de teclado:** Ctrl+Enter para avançar, Ctrl+S para salvar rascunho.
- **Preview do destinatário aleatório:** mostrar como ficaria a mensagem para o primeiro contato da lista com variáveis preenchidas (`{nome}`, `{telefone}`).
- **Estimativa de tempo de disparo** (ex: "5.000 contatos ≈ 12 min para concluir o envio").

---

## 2. Cobrança / Billing

### 🔴 Bloqueadores

- **`ConfirmSendModal.vue:91` vs `Step3Contacts.vue:190` vs `Plans.vue:77` — Três fontes de verdade conflitantes para preço.** Detalhado no Sumário. Risco regulatório (CDC, art. 31).
- **`Saldo.vue:91-100` — Input numérico de recarga sem máscara monetária.** Aceita `99.99` mas exibe "R$" fora do campo. Em mobile sem dot key (teclado pt-BR) o usuário pode digitar 999 e cobrar R$ 999 sem perceber.
- **`Saldo.vue:215` — `purchaseCredits(amountInCents, ...)` mas o backend recebe `credits_amount`.** Comentário do código (linha 200) admite "historically accepts" — comportamento legado frágil; um deploy pode inverter unidades e cobrar 100× a mais.
- **`BalanceBanner.vue:43-54` — Banner de saldo só aparece se `balance < 0 && limit > 0`.** Cliente prepaid com saldo positivo baixo (ex: R$ 2 com campanha de R$ 50 já calculada) não recebe alerta nenhum antes do disparo.

### 🟡 Pontos de Atenção

- **`Saldo.vue:24-37` — Banner "Conta bloqueada" oferece WhatsApp do suporte mas usa número fallback `5511999999999`** (linha 181). Em produção sem env, o cliente clica e vai para um número aleatório.
- **`Saldo.vue:155-166` — `nextBillingLabel` calcula localmente sem timezone tenant.** Tenant em UTC-5 pode ver "próxima cobrança 03/06" quando o backend já cobrou em 02/06 23:50 UTC.
- **`CheckoutThankYou.vue:9` — "Bem-vindo ao plano Pro" como fallback** (linha 47). Se o redirect perder o query param, todo cliente vê "Pro" mesmo tendo assinado Starter.
- **`CheckoutPage.vue:55` — Sem fallback de erro de pagamento aprovado parcialmente.** Se o token MP falhar após o usuário enviar dados, a UI só mostra "error in store" sem mensagem actionable.
- **`Plans.vue:55-60` — Botão "Plano grátis" disabled sem opção de downgrade.** Cliente em plano pago não consegue voltar ao Free.
- **`Plans.vue:77` — Aviso de preço em texto pequeno cinza centralizado**, sem destaque. Cliente espalha o olho na grade de planos e ignora os preços por envio.

### 🟢 Melhorias Propostas

- **Calculadora de "quanto preciso recarregar?"** baseada no histórico mensal médio.
- **Auto-recarga (top-up):** quando saldo < X, debitar Y. Reduz fricção.
- **Comparativo de planos** com slider de uso projetado (ex: "Com 10.000 envios/mês, o plano X economiza R$ Y").

---

## 3. Auditoria

### 🔴 Bloqueadores

- **Inexistência de tela de log do tenant.** Nenhuma página em `frontend/src/pages/` expõe `audit_log` / `activity_log` para o usuário comum. `CreditLimitModal.vue:48` menciona "Será registrado no audit log" mas não há onde consultar. **Compliance LGPD/SOC2 comprometida** — cliente não consegue provar quem disparou qual campanha.
- **`Detail.vue` (aba Envios) — Sem coluna "disparado por".** A tabela de dispatches (`Detail.vue:117-135`) não mostra qual usuário do tenant disparou, qual IP, qual user-agent. Em tenants multi-usuário é impossível imputar responsabilidade.
- **Histórico de edições da campanha invisível.** O auto-save (`Create.vue:324-341`) grava silenciosamente; não há "versionamento" nem "última edição por". Cliente não sabe se outro usuário do tenant mudou o conteúdo antes do disparo.

### 🟡 Pontos de Atenção

- **`reports/Credits.vue:91-103` — Coluna "Descrição" exibe texto bruto** sem link para a campanha originadora. Cliente vê "Debit campaign 1234" mas não consegue clicar para entender a transação.
- **Sem download de fatura/recibo** após pagamento de plano ou recarga. Empresas brasileiras precisam de NF para descontar.
- **Sem trilha de webhooks recebidos.** `settings/Webhooks.vue` configura webhook de saída; não há painel "últimos eventos enviados" com payload, status HTTP, retentativa.

### 🟢 Melhorias Propostas

- Página `/settings/audit-log` com filtros por usuário, ação, período, recurso, IP.
- Exportar audit log em CSV/JSON.
- "Quem editou esta campanha?" tooltip ao lado do nome no `Detail.vue`.

---

## 4. Relatórios

### 🔴 Bloqueadores

- **`reports/Campaigns.vue:26-32` — Filtro de canal omite WhatsApp.** Tenant que usa WhatsApp não consegue filtrar relatórios por ele.
- **`reports/CampaignDetail.vue:134` — Botão "Ver todos os dispatches" sem handler.** Pura decoração. Cliente clica e nada acontece.
- **`reports/CampaignDetail.vue:233-240` — `channelBadge` não cobre WhatsApp** — todo card de WhatsApp aparece como "secondary" cinza, indistinguível.
- **`campaigns/Index.vue:195-199` — `channelMap` não tem WhatsApp** — coluna "Canal" exibe `whatsapp` em texto bruto sem cor/badge.

### 🟡 Pontos de Atenção

- **`reports/Campaigns.vue:218-224` — `channelBadge` definido mas nunca usado** (a tabela usa `StatusBadge`); código morto = sinal de manutenção descuidada.
- **`reports/CampaignDetail.vue:78` — Timeline fixa em "últimas 24h"** sem seletor de período. Campanhas longas (drip 30d) ficam ilegíveis.
- **`reports/Credits.vue` — Sem coluna `reference_id`** com link para a campanha que originou o débito.
- **`Detail.vue:150-159` — Paginação só tem `<` e `>`, sem números de página e sem "X de Y".** Inconsistente com `campaigns/Index.vue:119-135` que tem paginação completa.
- **Sem comparação período a período.** Dashboard e Campaigns têm seletor de período mas não há overlay de "vs período anterior" (essencial em SaaS de marketing).

### 🟢 Melhorias Propostas

- **Drill-down nos KPIs** do `Detail.vue` (clicar em "Falhas" → filtra a aba Envios em failed).
- **Exportação agendada** (relatório semanal por email).
- **Heatmap horário** de melhor entrega por canal.

---

## 5. Fluxos Cross-cutting

### 🔴 Bloqueadores

- **`Detail.vue:17` — Botão "Cancelar" agendamento sem handler.** Crítico: campanhas agendadas que precisem ser canceladas (ex: erro de copy) **não podem ser canceladas pelo cliente**.
- **`Detail.vue:19` — Botão "Redefinir" para `status===failed` sem handler.** Campanhas falhas ficam órfãs — não há retry, não há reset.
- **Ausência total de gestão de opt-out / unsubscribe na UI.** `grep -i opt.?out|unsubscribe|descadastrar` retorna zero matches. Cliente brasileiro está fora de conformidade com a LGPD (art. 18 — direito de oposição), e nem em conformidade com 10DLC/CTIA para SMS US, nem com Lei do "STOP" do CAN-SPAM para email.
- **`contacts/Index.vue:347` — Exclusão de lista usa `window.confirm()`** ao invés do `ConfirmModal` (que existe e é usado no resto do app). Diálogo nativo do browser quebra a estética e não é estilizável.

### 🟡 Pontos de Atenção

- **`router/index.ts:25` — Redirect via `window.location.href`** força full reload em vez de rota SPA. Janela cinza visível por ~200ms.
- **`router/index.ts:259-282` — `beforeEach` não trata estado de tenant `blocked`/`suspended`.** Cliente bloqueado entra em qualquer tela e descobre o problema só ao tentar ação — feedback tardio.
- **`conversations/Index.vue:70` — Áudio de notificação `/sounds/notification.mp3`** carrega como recurso fixo sem opção de silenciar nas preferências do usuário. Anti-WCAG (1.4.2 — controle de áudio).
- **`Saldo.vue` — Cliente em billing_status='grace' vê 2 banners (`BalanceBanner` no topo + bloco específico no `Saldo.vue:11-22`)** — duplicidade visual.
- **`AppSidebar.vue:53-58` — Badge de "unread" em Conversas pode mostrar `unreadCount > 99`** sem limitar — pode quebrar o layout.
- **`EmailDomains.vue:17-87` — Onboarding em formato `<ol>` enorme com 7 passos sempre aberto por padrão.** Em segunda visita já saturou. Deveria persistir o "Recolher" em localStorage.
- **Sem dark-mode parity de cores hardcoded em `style="color:#ef4444"` e `style="color:#d97706"`** espalhados nos arquivos de Step. Quebra contraste no tema escuro.
- **Sem prevenção de duplo-clique no botão "Enviar Campanha Agora"** (`Create.vue:198-201`) — `disabled="isSending"` ajuda mas em conexões lentas o clique já foi disparado antes do estado mudar; deve usar `pointer-events: none` no primeiro clique.
- **`Step2WhatsApp.vue:62-64` — Variáveis com hardcoded "joão silva", "joão@email.com"** no preview. Em produção parece amador; deveria usar dado real do contato selecionado ou um faker localizado.

### 🟢 Melhorias Propostas

- **Onboarding tour guiado** (Shepherd/Driver.js) no primeiro login.
- **Página /help com FAQ** sobre opt-out, DNS, billing.
- **Skip-to-content** link para WCAG 2.4.1.
- **`prefers-reduced-motion`** já implementado em Step4Schedule mas inconsistente nos outros componentes — padronizar.

---

## 6. Veredito Final

- **Total de bloqueadores:** 14
- **Total de pontos de atenção:** 24
- **Total de melhorias:** 11
- **Pode lançar?** **NÃO.**

  Os 14 bloqueadores envolvem três classes de risco intoleráveis para um SaaS de cobrança por envio: (1) preço exibido divergente do preço cobrado em 3+ telas, (2) botões com função crítica (cancelar agendamento, redefinir failed, ver todos dispatches) sem implementação, (3) ausência total de auditoria visível ao tenant + ausência de gestão de opt-out, o que viola LGPD na largada. Recomendo bloqueio do go-live até que (no mínimo) os 14 bloqueadores sejam corrigidos, com nova rodada de auditoria de cobrança/UX após o fix.
