# UX/Design Improvements — Spec

**Date:** 2026-03-21
**Scope:** Frontend UX improvements for CampaignAI SaaS (Iteration 1)
**Stack:** Vue 3 + Tabler UI + Pinia + Axios

---

## 1. Wizard Step 3 — Dropdown + Preview

**Current state:** Campo numérico de `contact_list_id` sem UX.

**Target:**
- Select searchable com lista de `ContactList` do tenant
- Ao selecionar, renderiza card preview:
  - Nome da lista, total de contatos
  - Preview dos 5 primeiros contatos (nome + telefone mascarado)
  - Estimativa de créditos: `contatos × custo_por_canal`
- Botão "Criar nova lista" abre modal inline (nome + descrição, POST `/contact-lists`)
- Se nenhuma lista existe, mostra empty state com CTA para criar

**UX States:**
- **Loading:** Spinner no card preview enquanto busca contatos
- **Erro:** Toast se fetch falhar, card mostra "Erro ao carregar preview"
- **Lista vazia (0 contatos):** Card mostra warning inline "Esta lista não tem contatos. Adicione contatos antes de continuar." + botão Avançar desabilitado

**Files affected:**
- `frontend/src/pages/campaigns/Create.vue` — step 3 section
- New: `frontend/src/components/campaigns/steps/Step3Contacts.vue`

**API dependencies:**
- `GET /v1/contact-lists` — já existe, retorna listas do tenant
- `GET /v1/contacts?contact_list_id={id}` — já existe (paginate 20). Frontend usa apenas os primeiros 5 do response.
- `POST /v1/contact-lists` — já existe

**Backend change needed:** Adicionar param `per_page` ao `ContactsController::index()`:
```php
$perPage = min((int) $request->input('per_page', 20), 50);
$items = $q->orderBy('id', 'desc')->paginate($perPage);
```

**Validation:**
- `contact_list_id` required antes de avançar para step 4
- Lista deve ter ≥1 contato para avançar (verificar `contact_count` no response da lista)

---

## 2. Modal de Confirmação de Disparo

**Current state:** Botão "Enviar agora" dispara sem confirmação.

**Target:**
- Modal com resumo completo:
  - Nome da campanha, canal (badge), lista de contatos
  - Total de destinatários
  - Custo estimado em créditos (destinatários × unit rate do canal)
  - Saldo atual e saldo restante após envio
  - Preview truncado do conteúdo (primeiros 200 chars)
- Warning amarelo: "Esta ação não pode ser desfeita"
- Botões: "Cancelar" (outline) + "Confirmar Disparo" (vermelho)
- Loading state no botão após confirmar

**Files affected:**
- New: `frontend/src/components/campaigns/ConfirmSendModal.vue`
- `frontend/src/pages/campaigns/Index.vue` — trigger no botão "Enviar agora"
- `frontend/src/pages/campaigns/Detail.vue` — trigger no botão "Enviar agora"

**API dependencies:**
- `POST /v1/campaigns/{id}/send-now` — já existe
- `GET /v1/auth/me` — já retorna `tenant.credits_balance`

**Props do componente:**
```ts
interface ConfirmSendModalProps {
  campaign: {
    id: number
    name: string
    type: 'sms' | 'voice' | 'email'
    content?: string
    subject?: string
    estimated_contacts: number
    contact_list?: { name: string }
  }
  creditsBalance: number
}
```

**Eventos:** `@confirmed` → chama sendNow, `@cancelled` → fecha modal

---

## 3. Lista de Campanhas — Tabela Rica

**Current state:** Tabela simples sem filtros, paginação ou colunas informativas.

**Target:**
- Barra de filtros no topo:
  - Input busca (debounce 300ms) — filtra por nome
  - Select canal: valor `''` (Todos) / `sms` / `voice` / `email`
  - Select status: valor `''` (Todos) / `draft` / `scheduled` / `processing` / `completed` / `failed`
  - Labels em PT: Rascunho, Agendada, Em execução, Concluída, Falhou
- Tabela com colunas:
  - Nome (font-weight 600, clickável → detail)
  - Canal (badge colorido)
  - Status (badge com ícone ●/◉/✕)
  - Contatos (número)
  - Taxa entrega (% com cor semântica: verde ≥80, amarelo ≥50, vermelho <50, — se draft)
  - Criada em (dd/MM format)
  - Ações (⋮ dropdown: Ver detalhes, Editar, Duplicar, Enviar agora, Deletar)
- Paginação no rodapé: "Mostrando X-Y de Z campanhas" + botões de página
- Hover state nas linhas (background sutil)
- Row click → navega para `/campaigns/:id`

**Status enum mapping (DB → Label PT):**
| DB value | Label | Badge color |
|----------|-------|-------------|
| `draft` | Rascunho | amarelo |
| `scheduled` | Agendada | azul |
| `processing` | Processando | azul pulsante |
| `running` | Em execução | azul pulsante |
| `completed` | Concluída | verde |
| `failed` | Falhou | vermelho |

**Files affected:**
- `frontend/src/pages/campaigns/Index.vue` — rewrite completo

**API changes:**
- `GET /v1/campaigns` — adicionar query params: `search`, `type`, `status`

**Backend change needed — `CampaignsController::index()`:**
```php
public function index(Request $request)
{
    $validated = $request->validate([
        'search' => ['nullable', 'string', 'max:255'],
        'type'   => ['nullable', 'in:sms,voice,email'],
        'status' => ['nullable', 'in:draft,scheduled,processing,running,completed,failed'],
    ]);

    $q = Campaign::query()->with('contactList:id,name,contact_count');

    if (!empty($validated['search'])) {
        $q->where('name', 'like', '%'.$validated['search'].'%');
    }
    if (!empty($validated['type'])) {
        $q->where('type', $validated['type']);
    }
    if (!empty($validated['status'])) {
        $q->where('status', $validated['status']);
    }

    $paginator = $q->orderByDesc('id')->paginate(20);
    return ApiResponse::paginated($paginator, 'OK');
}
```

**Nota:** `Campaign` usa trait `AppliesTenantScope` que filtra por `tenant_id` automaticamente (superadmin faz bypass para ver todos).

---

## 4. Import CSV — Wizard 3 Etapas

**Current state:** Modal com file input + mapping fixo, sem preview nem progresso.

**Target — Etapa 1 (Upload):**
- Drag-drop zone (ou click para selecionar)
- Aceita `.csv` e `.txt`, max 10MB
- Link "Download template CSV" (gera CSV de exemplo client-side)
- Select de lista destino (ou "Criar nova lista")

**Target — Etapa 2 (Preview + Mapping):**
- Lê primeiras 5 linhas do CSV client-side (FileReader API)
- Mostra tabela preview com headers auto-detectados
- Cada coluna tem select para mapping: Telefone / Nome / Email / Ignorar
- Auto-detect de colunas por nome (phone/telefone → Telefone, etc.)
- Contadores: válidos, inválidos (telefone/email mal formatado), duplicados
- Validação client-side básica antes de enviar

**Target — Etapa 3 (Importando):**
- POST `/v1/contacts/import` com file + mapping
- Polling `GET /v1/contacts/import/{id}` com backoff: 2s, 4s, 8s, max 10s
- Barra de progresso: `processed_rows / total_rows`
- Ao concluir: mostra resumo (importados, ignorados, erros)
- Botão "Concluir" fecha modal e recarrega lista de contatos

**Files affected:**
- `frontend/src/components/contacts/ImportCsvModal.vue` — rewrite completo

**API dependencies:**
- `POST /v1/contacts/import` — já existe
- `GET /v1/contacts/import/{id}` — já existe, retorna status + processed_rows

**Backend change needed:** Adicionar validação de tamanho no `ImportController::upload()`:
```php
'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
```

---

## 5. Página de Contatos — Sidebar + Batch Actions

**Current state:** Tabela simples com busca, sem paginação, sem gestão de listas, sem ações em lote.

**Target — Sidebar de Listas:**
- Painel lateral esquerdo (200px, collapsible em mobile)
- Lista todas as `ContactList` do tenant com contagem
- `GET /v1/contact-lists` retorna todas (sem paginação, já que a quantidade de listas por tenant é limitada pelo plano)
- Item "Todas" no topo (soma total)
- Click filtra a tabela por `contact_list_id`
- Item selecionado tem highlight (background azul claro)
- Inline actions: renomear (ícone lápis), deletar (com confirmação)
- Botão "+ Nova lista" no rodapé do sidebar

**Target — Tabela com Filtros e Paginação:**
- Busca por nome/telefone/email (debounce 300ms)
- Filtro por status (ativo/bloqueado/inválido)
- Paginação no rodapé com contagem: "X contatos · Página Y de Z"
- 20 itens por página

**Target — Batch Actions:**
- Checkbox em cada linha + select-all no header
- Ao selecionar ≥1, barra azul aparece no topo da tabela:
  - "N selecionados"
  - Botão "Mover para lista" (abre dropdown com listas)
  - Botão "Mudar status" (dropdown: ativo/bloqueado/inválido)
  - Botão "Deletar" (vermelho, com confirmação modal)
  - Botão ✕ para limpar seleção

**Files affected:**
- `frontend/src/pages/contacts/Index.vue` — rewrite completo
- New: `frontend/src/components/contacts/ContactsSidebar.vue`
- New: `frontend/src/components/contacts/BatchActionsBar.vue`

**API changes needed (backend):**

**New endpoint `POST /v1/contacts/batch`** — `ContactsController::batch()`:
```php
public function batch(Request $request)
{
    $validated = $request->validate([
        'action'         => ['required', 'in:move,status,delete'],
        'contact_ids'    => ['required', 'array', 'max:500'],
        'contact_ids.*'  => ['integer'],
        'target_list_id' => ['required_if:action,move', 'integer', 'exists:contact_lists,id'],
        'target_status'  => ['required_if:action,status', 'in:active,blocked,invalid'],
    ]);

    $contacts = Contact::whereIn('id', $validated['contact_ids']);

    switch ($validated['action']) {
        case 'move':
            $oldListIds = $contacts->pluck('contact_list_id')->unique();
            $contacts->update(['contact_list_id' => $validated['target_list_id']]);
            // Atualizar contact_count nas listas afetadas
            foreach ($oldListIds as $listId) {
                ContactList::where('id', $listId)->update([
                    'contact_count' => Contact::where('contact_list_id', $listId)->count()
                ]);
            }
            ContactList::where('id', $validated['target_list_id'])->update([
                'contact_count' => Contact::where('contact_list_id', $validated['target_list_id'])->count()
            ]);
            break;
        case 'status':
            $contacts->update(['status' => $validated['target_status']]);
            break;
        case 'delete':
            $listIds = $contacts->pluck('contact_list_id')->unique();
            $contacts->delete();
            foreach ($listIds as $listId) {
                ContactList::where('id', $listId)->update([
                    'contact_count' => Contact::where('contact_list_id', $listId)->count()
                ]);
            }
            break;
    }

    return ApiResponse::success([], 'Operação concluída');
}
```

**New route** em `routes/api.php`:
```php
Route::post('contacts/batch', [ContactsController::class, 'batch']);
```

**Existing endpoints (já funcionais):**
- `PUT /v1/contact-lists/{id}` — renomear
- `DELETE /v1/contact-lists/{id}` — deletar lista

---

## Design Decisions Log

| Questão | Opção escolhida | Alternativa descartada |
|---------|-----------------|----------------------|
| Step 3 Contatos | Dropdown + Preview | Cards visuais, Tabela compacta |
| Confirmação disparo | Modal resumo completo | Digitação "ENVIAR" |
| Lista campanhas | Tabela rica com filtros | Kanban por status |
| Import CSV | Wizard 3 etapas | Upload direto + status card |
| Contatos | Sidebar listas + Batch actions | Apenas paginação/filtros |

---

## Out of Scope (Iteration 2)

- Perfil/senha do usuário
- Billing/pagamento
- Forgot password / registro
- Acessibilidade ARIA
- Dark mode
- Testes automatizados frontend
- Admin Plans/Tenants CRUD completo
