# WhatsApp Inbox — Sub-projeto 2

**Date:** 2026-03-22
**Scope:** Tela de chat, lista de conversas, handoff bot↔humano, notificações
**Stack:** Vue 3 + Tabler UI (frontend), Laravel 12 (backend — 2 endpoints novos)
**Depends on:** Sub-projeto 1 (WhatsApp Foundation — Conversation, ConversationMessage models, ConversationController)

---

## Contexto

O Sub-projeto 1 entregou a fundação: models de Conversation/ConversationMessage, webhook de recebimento, e endpoints básicos (list, show, sendMessage). Este sub-projeto constrói a UI completa de Inbox e adiciona 2 endpoints de suporte.

---

## 1. Layout — 3 Colunas

Tela full-height sem scroll do layout principal. Rota: `/conversations`.

```
┌─────────────┬──────────────────────────┬────────────────┐
│  Lista      │  Chat                    │  Info Contato  │
│  (280px)    │  (flex)                  │  (300px)       │
│             │                          │  collapsible   │
│  Busca      │  Header (nome, ações)    │                │
│  Filtros    │  Mensagens (scroll)      │  Avatar        │
│  Conversas  │  Input (texto + enviar)  │  Dados contato │
│             │                          │  Dados conversa│
└─────────────┴──────────────────────────┴────────────────┘
```

---

## 2. Lista de Conversas (Coluna Esquerda)

**Componente:** `ConversationList.vue`

**Header:**
- Input de busca (debounce 300ms) — filtra por nome/telefone
- Filtros por status: pills `Todas | Bot | Humano | Fechadas`

**Cada item da lista:**
- Nome do contato (ou telefone se não tiver nome)
- Última mensagem (truncada 50 chars)
- Horário relativo (14:32, ontem, 19/03)
- Badge de status: 🤖 bot (verde), 👤 humano (azul), ✓ fechada (cinza)
- Badge unread count (vermelho) se > 0
- Borda lateral azul na conversa selecionada

**Dados:** `GET /v1/conversations?status={filter}&search={query}`

**Comportamento:**
- Click seleciona conversa e carrega chat
- Ordenada por `last_message_at` desc (API já faz isso)
- Polling a cada 5s para atualizar lista (apenas lista, não mensagens)

---

## 3. Chat (Coluna Central)

**Componente:** `ConversationChat.vue`

### 3.1 Header

- Nome do contato + telefone
- Status atual: "🤖 Bot ativo" / "👤 Atendimento humano" / "✓ Fechada"
- Botões de ação:
  - **"Assumir"** (visível quando status = `bot`) → `PATCH /conversations/{id}/status` body `{status: 'human'}`
  - **"Devolver ao bot"** (visível quando status = `human`) → `PATCH /conversations/{id}/status` body `{status: 'bot'}`
  - **"Fechar"** (sempre visível exceto se já fechada) → `PATCH /conversations/{id}/status` body `{status: 'closed'}`
  - **"Reabrir"** (visível quando status = `closed`) → `PATCH /conversations/{id}/status` body `{status: 'bot'}`

### 3.2 Área de Mensagens

- Background `#f0f2f5` (cinza WhatsApp)
- Bolhas de mensagem:
  - **Inbound (lead):** fundo branco, alinhado à esquerda, borda `0 8px 8px 8px`
  - **Outbound:** fundo `#d9fdd3` (verde WhatsApp), alinhado à direita, borda `8px 0 8px 8px`
- Indicador de sender em outbound: `🤖 Bot`, `👤 Nome do operador`, `📢 Campanha`, `🤖 IA`
- Horário no canto inferior direito da bolha
- Status de entrega em outbound: `✓` sent, `✓✓` delivered, `✓✓` azul read, `✕` failed (vermelho)
- Mensagens do tipo `template`: renderiza com fundo diferenciado (borda azul leve)
- Mensagens de mídia: placeholder "[Imagem]", "[Vídeo]", "[Áudio]", "[Documento]" com ícone (sem player neste sub-projeto)
- Scroll reverso: mensagens mais recentes no fundo, scroll automático ao receber nova
- Botão "Carregar anteriores" no topo (paginação, `page` param no GET)
- Espaço para sugestão IA (preparado, desabilitado):
  - Barra acima do input: "💡 Sugestão IA: ..." + botões "Usar" / "Ignorar" (cinza, disabled, tooltip "Disponível no plano com IA")
  - Sub-projeto 3 ativa isso

### 3.3 Input de Mensagem

- Campo de texto com border-radius arredondado (estilo WhatsApp)
- Botão 📎 (anexo — visual presente, click mostra toast "Em breve" neste sub-projeto)
- Botão ➤ de envio (azul, circular)
- Enter envia, Shift+Enter quebra linha
- Disabled quando conversa está `closed` (mostra "Reabra a conversa para enviar mensagens")
- POST `/v1/conversations/{id}/messages` com body `{text: "..."}`
- Ao enviar: adiciona bolha otimisticamente (status pending), atualiza quando API responde

---

## 4. Info do Contato (Coluna Direita)

**Componente:** `ConversationInfo.vue`

**Collapsible:** botão toggle no header do chat para esconder/mostrar.

**Conteúdo:**
- Avatar com iniciais (background azul)
- Nome do contato
- Telefone
- Email (se existir)

**Seção "Detalhes":**
- Lista de contatos a que pertence
- Status do contato (ativo/bloqueado/inválido)

**Seção "Conversa":**
- Modo atual (Bot/Humano/Fechada)
- Data de início (created_at da conversa)
- Total de mensagens
- Operador atribuído (se houver)

**Dados:** Vêm do `GET /v1/conversations/{id}` que já retorna `contact` com eager loading.

---

## 5. Handoff Bot ↔ Humano

### Novo endpoint backend

`PATCH /v1/conversations/{id}/status`

```php
public function updateStatus(int $id, Request $request)
{
    $conversation = Conversation::findOrFail($id);
    $data = $request->validate([
        'status' => ['required', 'in:bot,human,closed'],
    ]);

    $updates = ['status' => $data['status']];

    if ($data['status'] === 'human') {
        $updates['assigned_to'] = auth()->id();
    }
    if ($data['status'] === 'closed') {
        $updates['unread_count'] = 0;
    }

    $conversation->update($updates);
    return ApiResponse::success($conversation->fresh(), 'Status atualizado');
}
```

**Rota:** `Route::patch('conversations/{id}/status', [ConversationController::class, 'updateStatus']);`

### Fluxo de handoff

1. Lead envia mensagem → webhook cria ConversationMessage, conversa em `status=bot`
2. Bot responde automaticamente (sub-projeto 3 — stub por agora)
3. Operador vê conversa no inbox, clica **"Assumir"**
4. Frontend chama `PATCH /conversations/{id}/status` com `{status: 'human'}`
5. `assigned_to` é setado para o operador atual
6. Bot para de responder (sub-projeto 3 verifica `status !== 'bot'` antes de responder)
7. Operador responde via input → `POST /conversations/{id}/messages`
8. Operador clica **"Devolver ao bot"** → `PATCH` com `{status: 'bot'}`
9. Próxima mensagem do lead será processada pelo bot novamente

---

## 6. Notificações

### Badge no sidebar

**Componente:** Modificar `AppSidebar.vue`

- Novo endpoint: `GET /v1/conversations/unread-count` → retorna `{count: N}`
- Polling a cada 10s
- Badge vermelho ao lado de "Conversas" no sidebar: `<span class="badge bg-red">N</span>`
- Se count = 0, badge não aparece

```php
// ConversationController
public function unreadCount()
{
    $count = Conversation::where('unread_count', '>', 0)->count();
    return response()->json(['count' => $count]);
}
```

**Rota:** `Route::get('conversations/unread-count', [ConversationController::class, 'unreadCount']);`

### Som de notificação

- Arquivo de áudio: `frontend/public/sounds/notification.mp3` (som curto tipo WhatsApp)
- Toca quando o polling detecta novo unread_count > anterior
- Respeita `document.visibilityState` — só toca som se a aba está ativa mas o chat não é a conversa que recebeu mensagem

---

## 7. Polling Inteligente

**Implementação no composable `useConversationPolling.ts`:**

| Contexto | Intervalo | O que atualiza |
|----------|-----------|----------------|
| Lista de conversas | 5s | `GET /conversations` (atualiza lista) |
| Chat ativo (status bot/human) | 3s | `GET /conversations/{id}` (novas mensagens) |
| Chat ativo (status closed) | 15s | `GET /conversations/{id}` |
| Sidebar badge | 10s | `GET /conversations/unread-count` |
| Aba inativa (visibilitychange) | pausa todos | Retoma ao voltar |

**visibilitychange:**
```ts
document.addEventListener('visibilitychange', () => {
  if (document.hidden) pauseAllPolling()
  else resumeAllPolling()
})
```

---

## 8. Arquivos

### Backend — Criar
Nenhum arquivo novo. Apenas modificar `ConversationController.php`.

### Backend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `app/Http/Controllers/API/V1/ConversationController.php` | Adicionar `updateStatus()` e `unreadCount()` |
| `routes/api.php` | Adicionar 2 rotas: PATCH status, GET unread-count |

### Frontend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `src/pages/conversations/Index.vue` | Página principal do inbox (3 colunas) |
| `src/components/conversations/ConversationList.vue` | Lista de conversas com busca/filtro |
| `src/components/conversations/ConversationChat.vue` | Área de chat com mensagens e input |
| `src/components/conversations/ConversationInfo.vue` | Painel lateral de info do contato |
| `src/components/conversations/MessageBubble.vue` | Bolha de mensagem individual |
| `src/composables/useConversationPolling.ts` | Polling inteligente com visibilitychange |
| `public/sounds/notification.mp3` | Som de notificação |

### Frontend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `src/router/index.ts` | Mudar `/conversations` de Dashboard para `conversations/Index.vue` |
| `src/components/layout/AppSidebar.vue` | Adicionar badge unread no item Conversas |

---

## 9. Out of Scope

- Upload de mídia (botão 📎 presente mas desabilitado)
- Sugestão IA nas respostas (UI placeholder, lógica no sub-projeto 3)
- WebSocket/Pusher (polling é suficiente para MVP)
- Notas internas na conversa
- Atribuição de conversa a operador específico (auto-atribui quem assume)
- Histórico de handoffs
