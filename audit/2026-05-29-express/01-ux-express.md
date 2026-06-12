# UX/UI Express Re-audit — Commit 096c5c68
**Data:** 2026-05-29
**Auditor:** Designer Sênior UX/UI (re-auditoria express)
**Escopo:** verificação focada das 3 regressões UX da rodada anterior (`audit/2026-05-29/01-ux-ui-reaudit.md`) + caça a novas regressões introduzidas pelo commit `096c5c68` (`fix(security/billing): close 9 P0R blockers from independent re-audit`).

---

## 1. Status das regressões anteriores

### R1 — Rotas `/settings/opt-outs` e `/settings/audit-log` ausentes no router
**Status: FECHADA ✅**

Evidência: `frontend/src/router/index.ts:217-226` agora declara explicitamente:

```ts
{
  path: '/settings/opt-outs',
  component: () => import('@/pages/settings/OptOuts.vue'),
  meta: { title: 'Opt-outs' },
},
{
  path: '/settings/audit-log',
  component: () => import('@/pages/settings/AuditLog.vue'),
  meta: { title: 'Histórico de Auditoria' },
},
```

Sidebar (`frontend/src/components/layout/AppSidebar.vue:161-173`) aponta para esses paths via `go('/settings/opt-outs')` e `go('/settings/audit-log')` — caminhos batem com router. O link de Auditoria fica gated por `v-if="auth.user && ['admin','superadmin'].includes(auth.user.role)"` — coerente com 403 server-side. Os componentes carregam sem erro: `OptOuts.vue` é coerente (`onMounted(reload)`, ConfirmModal de remoção, modal de adicionar, importação CSV, paginação) e `AuditLog.vue` trata 403 com `forbidden.value = true` exibindo alert dedicado em vez de toast genérico.

Resultado: P0-07 (LGPD opt-out) e P0-11 (LGPD audit log) agora são efetivamente acessíveis end-to-end.

---

### R2 — `creditsPerSend = ref(1)` literal em `Create.vue`
**Status: FECHADA ✅**

Evidência: `frontend/src/pages/campaigns/Create.vue:293`:

```ts
// Preço unitário REAL do canal — vem de /account/pricing (fonte única, P0-03).
// Antes era `ref(1)` literal → cartão "Custo Total Estimado" mostrava 1¢/envio
// independentemente da tarifa real cobrada pelo backend.
const creditsPerSend = ref<number | null>(null)
```

E o watch reativo em `Create.vue:305-315` recarrega a tarifa sempre que `form.type` muda, consumindo `/account/pricing` e setando `creditsPerSend.value = Number(match.sale_cents)` (ou `null` se falhar). Falha graceful: `creditsPerSend.value = null` no catch.

O card "Custo Total Estimado" (linha 145) e o mini-modal de confirm-send (linha 230) ambos calculam `(contactsTotal || adhocPhones.length) * creditsPerSend`. **O props para `Step5Review` (linha 184) também recebe o valor reativo correto.** Quando `creditsPerSend === null`, `brl(null)` precisa ser checado (ver achado novo abaixo).

---

### R3 — Botão "Ver todos os dispatches" sem handler
**Status: AINDA ABERTA ❌**

Evidência: `frontend/src/pages/reports/CampaignDetail.vue:133-137`:

```html
<div class="card-footer">
  <button class="btn btn-ghost-secondary btn-sm">
    Ver todos os dispatches
  </button>
</div>
```

Continua sem `@click`, sem `:to`, sem handler. Cliente vê apenas amostra parcial e o botão é estético. **Não foi tocado pelo commit `096c5c68`** (não aparece no diffstat). Era item A18 (atenção) na rodada original, escalado para bloqueador na re-auditoria, e continua em aberto.

---

### R4 — `contacts/Index.vue` usando `window.confirm()` nativo
**Status: AINDA ABERTA ❌**

Evidência: `frontend/src/pages/contacts/Index.vue:383-384`:

```ts
async function deleteList(id: number) {
  if (!confirm('Tem certeza que deseja excluir esta lista?')) return
  ...
```

Inalterado. `ConfirmModal` segue importado e usado em `confirmBatchDelete`, mas `deleteList` continua com o nativo. **Não foi tocado pelo commit `096c5c68`.** Quebra consistência visual + WCAG + impossibilita testes E2E que dependam do componente.

---

## 2. Novas regressões UX detectadas

### N1 — `Create.vue` sem `pricingLoading` / spinner / disable de avanço
**Severidade: BLOQUEADOR.**
**Local:** `frontend/src/pages/campaigns/Create.vue:266-348`.

O fix do `creditsPerSend` foi feito **sem estado de loading**. Não há `pricingLoading` nem `pricingError` no Create.vue (`grep "pricingLoading|pricingError"` retorna zero matches). Consequências:

1. Entre o mount do Step 2 e a resposta de `/account/pricing`, `creditsPerSend.value === null`. As linhas 145, 146 e 230 chamam `brl(null)` — dependendo da implementação de `brl()` pode render `R$ 0,00`, `R$ NaN` ou crashear. Cliente decide tamanho de campanha vendo "R$ 0,00 por sms" por uma janela.
2. `canAdvance` (linha 336-348) **não bloqueia avanço** enquanto tarifa não carrega. Cliente que abra o Create.vue e clique "Avançar" → "Revisar Campanha" sem esperar o fetch chega ao Step 5 com `currentBalance` válido mas `creditsPerSend = null` — `Step5Review` precisa lidar com isso ou o cálculo de "Saldo restante" quebra.
3. Se `/account/pricing` falhar (tenant sem plano, rede flap), o usuário não vê erro: simplesmente vê "R$ 0,00" no Custo Total + botão "Enviar Campanha Agora" habilitado. **Reintrodução parcial do P0-03** num cenário de borda — modal grande de confirmação (`ConfirmSendModal`) ainda bloqueia o envio quando `unitRate` falha, mas o mini-modal inline do `Create.vue:221-243` confirma com `creditsPerSend` null → cliente envia sem ver o valor.

Comparativo: `Step3Contacts.vue` tem `pricingLoading/pricingError` (citado no relatório anterior B4); `ConfirmSendModal.vue` desabilita "Confirmar Disparo" enquanto carrega. O orquestrador `Create.vue` ignorou esse padrão.

### N2 — `OptOuts.vue` download via `window.open` sem header Authorization
**Severidade: ATENÇÃO.**
**Local:** `frontend/src/pages/settings/OptOuts.vue:269-272`.

```ts
async function downloadCsv() {
  window.open('/api/v1/messaging/opt-outs/export', '_blank')
}
```

`window.open` não envia o header `Authorization: Bearer <token>` da Sanctum SPA. Se o backend exige Bearer (e o restante do app usa Bearer via axios interceptor), o export será 401 silencioso. Sem feedback de erro para o usuário. Já apontado como N4 na rodada anterior; **não corrigido**.

### N3 — `AuditLog.vue` filtros não preservam estado na URL
**Severidade: ATENÇÃO.**
**Local:** `frontend/src/pages/settings/AuditLog.vue:144-177`.

`filters = reactive({ action, resource, from, to })` é só estado local. Investigador que compartilha link com colega ou recarrega perde os filtros. Para uma página de compliance/forensics, deeplink é essencial. Já apontado como N5 na rodada anterior; **não corrigido**.

### N4 — `OptOuts.vue` modal "Adicionar opt-out" sem `<teleport>` / Esc / trap de foco
**Severidade: ATENÇÃO.**
**Local:** `frontend/src/pages/settings/OptOuts.vue:117-156`.

Mesmo padrão WCAG-violando do mini-modal de Create.vue. Sem teleport (modal fica preso na hierarquia DOM da página), sem `@keyup.esc` para fechar, sem trap de foco. Apontado como N7 na rodada anterior; **não corrigido**.

### N5 — `AuditLog.vue` `<pre>` quebra layout em metadata grandes
**Severidade: MELHORIA.**
**Local:** `frontend/src/pages/settings/AuditLog.vue:93-94`.

`<pre style="max-width:300px;white-space:pre-wrap;word-break:break-all">` em payloads grandes (snapshot de campanha completa, settings JSON) gera célula vertical enorme empurrando linhas abaixo. Apontado como N12 na rodada anterior; **não corrigido**.

### N6 — `changePassword` no frontend NÃO redireciona para login após revogação de tokens (NOVO — introduzido por P0R-04)
**Severidade: BLOQUEADOR.**
**Local backend:** `backend/app/Http/Controllers/API/V1/AuthController.php:184-206`.
**Local frontend:** `frontend/src/pages/profile/Index.vue:170-183`.

O commit `096c5c68` adicionou `$user->tokens()->delete()` no `changePassword` (P0R-04) — **boa decisão de segurança**, MAS o frontend não foi adaptado:

```ts
async function changePassword() {
  if (!validatePassword()) return
  changingPw.value = true
  try {
    await put<any>('/auth/password', pw.value)
    toast.success('Senha alterada com sucesso')
    pw.value = { ... }
    pwErrors.value = {}
  } catch (e: any) {
    toast.error(...)
  } finally {
    changingPw.value = false
  }
}
```

A resposta do backend já vem com a mensagem `'Senha alterada com sucesso. Faça login novamente.'`, mas o frontend exibe apenas `'Senha alterada com sucesso'` (hardcoded, ignora o message do response) e **NÃO chama `auth.logout()` nem `router.push('/login')`**. Como o token corrente foi deletado server-side, a próxima requisição (qualquer fetch que o app fizer) cairá em 401 → interceptor 401 redireciona para login (se existe) ou simplesmente quebra a UX. O cliente vê "Senha alterada com sucesso" e depois telas vazias / erros 401 surgindo do nada.

**Esta é uma regressão UX clara introduzida por este commit:** o backend mudou contrato (sessão termina) mas o frontend mantém o fluxo antigo (mantém usuário logado). Resultado: experiência confusa. Bloqueia lançamento.

### N7 — `currentBalance` no Create.vue não atualiza após envio (NOVO derivado de P0R-04 + P0R-07)
**Severidade: MELHORIA.**
**Local:** `frontend/src/pages/campaigns/Create.vue:317-320`.

`currentBalance = computed(() => auth.user?.tenant?.balance_cents ?? 0)` lê do auth store; o store só é atualizado em login/me. Após um disparo bem-sucedido, o saldo no backend muda (debitado), mas o card "Saldo Disponível" no Step 2 continua mostrando o valor antigo até refresh. Cliente que cria duas campanhas em sequência vê saldo errado na segunda. Não-bloqueante mas inconsistente.

### N8 — Comentário enganoso no `Step5Review` props sobre `null` (NOVO contradição interna)
**Severidade: MELHORIA.**
**Local:** `Create.vue:184` + `Step5Review.vue` props.

Create.vue agora passa `:credits-per-send="creditsPerSend"` que pode ser `null`. A rodada anterior reportou que `Step5Review.vue:139-176` já lida com `null` mostrando "—". OK em runtime, mas o cálculo "Saldo restante após envio" depende de cents válidos. Quando tarifa é null + cliente clica "Confirmar envio" no mini-modal inline (que NÃO consome `ConfirmSendModal`), o envio dispara sem validar saldo. Combina com N1.

---

## 3. Veredito UX

**NÃO LANÇAR.**

Justificativa:

1. **Regressões anteriores fechadas:** 2 das 4 (R1 router + R2 creditsPerSend literal). As outras 2 (R3 "Ver todos os dispatches" + R4 `confirm()` nativo em `contacts/Index.vue`) **não foram tocadas pelo commit `096c5c68`** — esperado, pois o commit foca em segurança/billing, mas continuam pendentes para liberação UX.

2. **Novo bloqueador UX introduzido pelo próprio commit (N6):** P0R-04 revoga todos os tokens server-side ao trocar senha, mas o frontend `profile/Index.vue:174-176` não foi atualizado para chamar `auth.logout()` e `router.push('/login')`. Cliente troca senha, vê toast de sucesso, e a próxima ação dispara 401 fantasma. Quebra de fluxo crítica em página de segurança. Diretamente atribuível a este commit.

3. **Novo bloqueador UX por omissão (N1):** o fix do `creditsPerSend` foi feito sem `pricingLoading`/`pricingError` no orquestrador. Janela de "R$ 0,00" + nenhum bloqueio de `canAdvance` enquanto carrega + mini-modal inline confirma com null. Padrão de loading já existia em `Step3Contacts.vue` e `ConfirmSendModal.vue` — não foi replicado aqui.

4. **Cinco achados N2-N5, N7-N8 reabertos/persistentes:** export sem auth header, filtros não-deeplinkáveis, modal sem teleport, currentBalance sem refresh pós-envio.

**Bloqueadores P0 UX restantes para liberar:**

- N1 — adicionar `pricingLoading`/`pricingError` em `Create.vue` e gating de `canAdvance` (`canAdvance && pricingReady`).
- N6 — após `changePassword` 200, chamar `auth.logout()` + `router.push('/login')` com toast persistente "Senha alterada. Faça login novamente."
- R3 — handler do botão "Ver todos os dispatches" (rota dedicada ou expandir tabela).
- R4 — substituir `confirm()` por `ConfirmModal` em `contacts/Index.vue:384`.

Estimativa de fix: **2-3 horas**. Após fechados + reverificação, UX libera para lançamento.

**Fim da re-auditoria UX express.**
