# Funil Visual — Sub-projeto 4

**Date:** 2026-03-22
**Scope:** Editor visual drag-and-drop de funis WhatsApp, engine de execução com cron, triggers por palavra-chave
**Stack:** Vue 3 + Vue Flow (frontend), Laravel 12 (backend)
**Depends on:** Sub-projeto 1 (WhatsApp Foundation), Sub-projeto 2 (Inbox), Sub-projeto 3 (IA Conversacional)

---

## Contexto

Os sub-projetos 1-3 entregaram: envio WhatsApp, inbox de conversas, e IA conversacional. Este sub-projeto adiciona automação: funis visuais onde o tenant cria sequências de mensagens, delays e condições que executam automaticamente.

### Decisões de design

| Questão | Decisão |
|---------|---------|
| Editor visual | Vue Flow (`@vue-flow/core`) — fluxograma conectado |
| Triggers | Palavra-chave + default catch-all + manual via campanha |
| Prioridade | Funil atual tem prioridade; operador pode redirecionar |
| Engine de execução | Cron a cada minuto (batch processing) |
| Guard | Contato só entra em funil se NÃO está em funil ativo |

---

## 1. Tipos de Nós

### 1.1 Mensagem (verde `#16a34a`)
Envia mensagem WhatsApp ao contato.

**Config (JSON):**
```json
{
  "message_type": "text",       // "text" ou "template"
  "text": "Olá! Bem-vindo...",  // se text
  "template_name": null,        // se template
  "template_language": null,
  "template_components": []
}
```

**Execução:** Chama `WhatsAppService::sendText()` ou `sendTemplate()`. Cria `ConversationMessage` com `sender_type=bot`. Avança para próximo nó.

### 1.2 Espera (amarelo `#f59e0b`)
Pausa a execução por tempo configurável.

**Config:**
```json
{
  "duration": 2,
  "unit": "hours"    // "minutes", "hours", "days"
}
```

**Execução:** Seta `FunnelExecution.wait_until = now() + duration`. Status muda para `waiting`. Cron retoma quando `wait_until <= now()`.

### 1.3 Condição (azul `#3b82f6`)
Ramifica o fluxo com base em critérios. Tem 2+ saídas (edges com labels).

**Config:**
```json
{
  "condition_type": "replied",     // "replied", "keyword", "timeout"
  "keyword": null,                 // regex se tipo=keyword (ex: "comprar|preço")
  "timeout_duration": 24,         // horas para timeout
  "timeout_unit": "hours"
}
```

**Tipos de condição:**
- `replied` — contato respondeu desde o último nó de mensagem? Saídas: "Sim" / "Não"
- `keyword` — resposta contém palavra-chave (regex match)? Saídas: "Match" / "Sem match"
- `timeout` — contato respondeu dentro do prazo? Saídas: "Respondeu" / "Timeout". Funciona como espera + condição combinada

**Execução:** Avalia condição, segue a edge correspondente.

### 1.4 Tag (rosa `#ec4899`)
Adiciona ou remove tag no contato.

**Config:**
```json
{
  "action": "add",        // "add" ou "remove"
  "tag": "interessado"
}
```

**Execução:** Atualiza `Contact.meta.tags[]`. Avança para próximo nó imediatamente.

---

## 2. Models

### 2.1 Funnel

**File:** `backend/app/Models/Funnel.php`
**Com** AppliesTenantScope.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `tenant_id` | bigint FK | |
| `name` | string | Nome do funil |
| `description` | text nullable | Descrição opcional |
| `status` | enum `draft,active,paused` | |
| `is_default` | boolean default false | Catch-all para mensagens sem match |
| `triggers` | JSON | Array de triggers `[{type, keyword, funnel_id}]` |
| `created_at/updated_at` | timestamps | |

**Regra:** Apenas 1 funil pode ser `is_default=true` por tenant.

**Triggers JSON:**
```json
[
  {"type": "keyword", "pattern": "oi|olá|hello"},
  {"type": "keyword", "pattern": "comprar|preço|valor"},
  {"type": "default"}
]
```

**Relationships:**
- `hasMany(FunnelNode)`
- `hasMany(FunnelEdge)`
- `hasMany(FunnelExecution)`

### 2.2 FunnelNode

**File:** `backend/app/Models/FunnelNode.php`
**Sem** AppliesTenantScope (acessado via Funnel).

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `funnel_id` | bigint FK | |
| `node_id` | string | ID único no editor (ex: "node_1") |
| `type` | enum `start,message,wait,condition,tag` | |
| `label` | string nullable | Label visual no editor |
| `config` | JSON | Configuração específica do tipo |
| `position_x` | float | Posição X no canvas |
| `position_y` | float | Posição Y no canvas |
| `created_at/updated_at` | timestamps | |

**Nó `start`:** Nó especial que marca o início do funil. Não tem config. Cada funil tem exatamente 1.

### 2.3 FunnelEdge

**File:** `backend/app/Models/FunnelEdge.php`
**Sem** AppliesTenantScope.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `funnel_id` | bigint FK | |
| `edge_id` | string | ID único no editor |
| `source_node_id` | string | node_id de origem |
| `target_node_id` | string | node_id de destino |
| `label` | string nullable | "Sim", "Não", "Match", etc. |
| `created_at/updated_at` | timestamps | |

### 2.4 FunnelExecution

**File:** `backend/app/Models/FunnelExecution.php`
**Com** AppliesTenantScope.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `tenant_id` | bigint FK | |
| `funnel_id` | bigint FK | |
| `contact_id` | bigint FK | |
| `conversation_id` | bigint FK nullable | |
| `current_node_id` | string | node_id do nó atual |
| `status` | enum `running,waiting,completed,cancelled` | |
| `wait_until` | datetime nullable | Quando retomar (para blocos de espera) |
| `last_message_id` | bigint nullable | ID da última msg enviada (para condição "replied") |
| `metadata` | JSON nullable | Dados acumulados durante execução |
| `started_at` | datetime | |
| `completed_at` | datetime nullable | |
| `created_at/updated_at` | timestamps | |

**Índices:** `(status, wait_until)` — para o cron buscar execuções prontas.

---

## 3. Trigger System

### Fluxo de trigger

```
Mensagem inbound chega (webhook)
    │
    ▼
Contato tem FunnelExecution ativa (running/waiting)?
├─ Sim → NÃO aciona novo funil (funil atual tem prioridade)
│        └─ Porém: se está em nó de condição "replied/keyword", marca como respondeu
│
├─ Não → Buscar funil por palavra-chave
│        ├─ Match encontrado → Iniciar FunnelExecution nesse funil
│        ├─ Sem match → Funil default (is_default=true) se existir
│        └─ Sem default → ProcessInboundMessageJob normal (IA/bot)
```

### Implementação no WhatsAppWebhookController

Após `processInboundMessage()`, antes de despachar `ProcessInboundMessageJob`:

```php
// Verificar se contato já está em funil ativo
$activeExecution = FunnelExecution::withoutGlobalScopes()
    ->where('tenant_id', $tenantId)
    ->where('contact_id', $contact->id)
    ->whereIn('status', ['running', 'waiting'])
    ->first();

if ($activeExecution) {
    // Marcar que contato respondeu (para condições)
    $activeExecution->update(['metadata->last_reply_at' => now()]);
    // Se está em nó de condição do tipo "replied" ou "keyword", avançar
    FunnelConditionCheckJob::dispatch($activeExecution->id);
} else {
    // Tentar match de trigger
    $funnel = $this->matchFunnel($content, $tenantId);
    if ($funnel) {
        StartFunnelExecutionJob::dispatch($funnel->id, $contact->id, $conversation->id, $tenantId);
    } else {
        // Fallback para bot/IA normal
        ProcessInboundMessageJob::dispatch(...);
    }
}
```

### matchFunnel()

```php
private function matchFunnel(string $content, int $tenantId): ?Funnel
{
    $funnels = Funnel::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->where('status', 'active')
        ->get();

    // Primeiro: buscar por keyword match
    foreach ($funnels as $funnel) {
        foreach ($funnel->triggers ?? [] as $trigger) {
            if ($trigger['type'] === 'keyword' && !empty($trigger['pattern'])) {
                if (preg_match('/' . $trigger['pattern'] . '/i', $content)) {
                    return $funnel;
                }
            }
        }
    }

    // Fallback: funil default
    return $funnels->firstWhere('is_default', true);
}
```

### Redirecionamento manual (operador)

Novo endpoint: `POST /v1/funnels/{funnelId}/enroll`

```php
// Cancela execução atual (se existir) e inicia nova
$request->validate(['contact_id' => 'required|integer|exists:contacts,id']);
```

---

## 4. Engine de Execução

### Comando Artisan: `funnel:tick`

**File:** `backend/app/Console/Commands/FunnelTickCommand.php`

Registrado no scheduler para rodar a cada minuto.

```php
// routes/console.php
Schedule::command(FunnelTickCommand::class)
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
```

### Fluxo do tick

```
funnel:tick executa
    │
    ▼
Buscar FunnelExecution WHERE status='waiting' AND wait_until <= now()
    │
    ▼
Para cada execução (chunk de 100):
    │
    ▼
Carregar nó atual (FunnelNode)
    │
    ▼
Avançar para próximo nó via edge
    │
    ▼
Executar nó:
├─ message → enviar via WhatsApp → avançar
├─ wait → setar wait_until → status=waiting → parar
├─ condition → avaliar → seguir edge correta → avançar
├─ tag → aplicar tag → avançar
└─ sem próximo nó → status=completed
```

### FunnelEngineService

**File:** `backend/app/Services/Funnel/FunnelEngineService.php`

Orquestra a execução de um contato pelo funil.

```php
class FunnelEngineService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private SettingsService $settings
    ) {}

    /**
     * Avança a execução para o próximo nó e executa.
     * Pode avançar múltiplos nós em sequência (message → tag → wait).
     */
    public function advance(FunnelExecution $execution): void
    {
        $maxSteps = 20; // previne loop infinito
        $steps = 0;

        while ($steps < $maxSteps) {
            $steps++;

            $currentNode = FunnelNode::where('funnel_id', $execution->funnel_id)
                ->where('node_id', $execution->current_node_id)
                ->first();

            if (! $currentNode) {
                $execution->update(['status' => 'completed', 'completed_at' => now()]);
                return;
            }

            // Encontrar próximo nó via edge
            $nextNodeId = $this->resolveNextNode($execution, $currentNode);

            if (! $nextNodeId) {
                $execution->update(['status' => 'completed', 'completed_at' => now()]);
                return;
            }

            $execution->update(['current_node_id' => $nextNodeId]);

            $nextNode = FunnelNode::where('funnel_id', $execution->funnel_id)
                ->where('node_id', $nextNodeId)
                ->first();

            if (! $nextNode) {
                $execution->update(['status' => 'completed', 'completed_at' => now()]);
                return;
            }

            // Executar o nó
            $shouldContinue = $this->executeNode($execution, $nextNode);

            if (! $shouldContinue) {
                return; // Nó de espera ou condição com timeout parou a execução
            }
        }
    }

    private function executeNode(FunnelExecution $execution, FunnelNode $node): bool
    {
        return match ($node->type) {
            'message'   => $this->executeMessage($execution, $node),
            'wait'      => $this->executeWait($execution, $node),
            'condition' => $this->executeCondition($execution, $node),
            'tag'       => $this->executeTag($execution, $node),
            'start'     => true, // Start node just passes through
            default     => true,
        };
    }
}
```

### executeMessage()

```php
private function executeMessage(FunnelExecution $execution, FunnelNode $node): bool
{
    $config = $node->config ?? [];
    $contact = Contact::withoutGlobalScopes()->find($execution->contact_id);
    if (! $contact) return true;

    $conversation = Conversation::withoutGlobalScopes()->find($execution->conversation_id);

    if (($config['message_type'] ?? 'text') === 'template') {
        $result = $this->whatsapp->sendTemplate(
            $contact->phone,
            $config['template_name'],
            $config['template_language'] ?? 'pt_BR',
            $config['template_components'] ?? [],
            $execution->tenant_id
        );
    } else {
        // Substituir variáveis no texto
        $text = str_replace(
            ['{nome}', '{telefone}', '{email}'],
            [$contact->name ?? '', $contact->phone ?? '', $contact->email ?? ''],
            $config['text'] ?? ''
        );
        $result = $this->whatsapp->sendText($contact->phone, $text, $execution->tenant_id);
    }

    if ($result['ok'] && $conversation) {
        $msg = ConversationMessage::forceCreate([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $execution->tenant_id,
            'direction'           => 'outbound',
            'sender_type'         => 'bot',
            'type'                => ($config['message_type'] ?? 'text') === 'template' ? 'template' : 'text',
            'content'             => $config['text'] ?? null,
            'template_name'       => $config['template_name'] ?? null,
            'external_message_id' => $result['message_id'],
            'status'              => 'sent',
            'created_at'          => now(),
            'sent_at'             => now(),
            'metadata'            => ['funnel_id' => $execution->funnel_id],
        ]);
        $execution->update(['last_message_id' => $msg->id]);
        $conversation->update(['last_message_at' => now()]);
    }

    return true; // Continua para próximo nó
}
```

### executeWait()

```php
private function executeWait(FunnelExecution $execution, FunnelNode $node): bool
{
    $config = $node->config ?? [];
    $duration = (int) ($config['duration'] ?? 1);
    $unit = $config['unit'] ?? 'hours';

    $waitUntil = match ($unit) {
        'minutes' => now()->addMinutes($duration),
        'hours'   => now()->addHours($duration),
        'days'    => now()->addDays($duration),
        default   => now()->addHours($duration),
    };

    $execution->update([
        'status'     => 'waiting',
        'wait_until' => $waitUntil,
    ]);

    return false; // Para a execução — cron retoma depois
}
```

### executeCondition()

```php
private function executeCondition(FunnelExecution $execution, FunnelNode $node): bool
{
    $config = $node->config ?? [];
    $type = $config['condition_type'] ?? 'replied';

    $result = match ($type) {
        'replied' => $this->checkReplied($execution),
        'keyword' => $this->checkKeyword($execution, $config['keyword'] ?? ''),
        'timeout' => $this->checkTimeout($execution, $config),
        default   => 'no',
    };

    // Encontrar a edge correta baseado no resultado
    $edges = FunnelEdge::where('funnel_id', $execution->funnel_id)
        ->where('source_node_id', $node->node_id)
        ->get();

    $matchLabel = match ($result) {
        'yes'  => ['Sim', 'sim', 'Match', 'match', 'Respondeu', 'respondeu', 'yes'],
        'no'   => ['Não', 'não', 'Sem match', 'sem match', 'Timeout', 'timeout', 'no'],
        default => ['no'],
    };

    $targetEdge = $edges->first(function ($edge) use ($matchLabel) {
        return in_array($edge->label, $matchLabel);
    }) ?? $edges->first(); // fallback para primeira edge

    if ($targetEdge) {
        $execution->update(['current_node_id' => $targetEdge->target_node_id]);
    }

    return true; // Continua executando o nó de destino
}

private function checkReplied(FunnelExecution $execution): string
{
    if (! $execution->last_message_id) return 'no';

    $hasReply = ConversationMessage::withoutGlobalScopes()
        ->where('conversation_id', $execution->conversation_id)
        ->where('direction', 'inbound')
        ->where('id', '>', $execution->last_message_id)
        ->exists();

    return $hasReply ? 'yes' : 'no';
}

private function checkKeyword(FunnelExecution $execution, string $pattern): string
{
    if (empty($pattern) || ! $execution->last_message_id) return 'no';

    $reply = ConversationMessage::withoutGlobalScopes()
        ->where('conversation_id', $execution->conversation_id)
        ->where('direction', 'inbound')
        ->where('id', '>', $execution->last_message_id)
        ->latest('created_at')
        ->first();

    if (! $reply || ! $reply->content) return 'no';

    return preg_match('/' . $pattern . '/i', $reply->content) ? 'yes' : 'no';
}
```

### executeTag()

```php
private function executeTag(FunnelExecution $execution, FunnelNode $node): bool
{
    $config = $node->config ?? [];
    $action = $config['action'] ?? 'add';
    $tag = $config['tag'] ?? '';

    if (empty($tag)) return true;

    $contact = Contact::withoutGlobalScopes()->find($execution->contact_id);
    if (! $contact) return true;

    $meta = $contact->meta ?? [];
    $tags = $meta['tags'] ?? [];

    if ($action === 'add' && ! in_array($tag, $tags)) {
        $tags[] = $tag;
    } elseif ($action === 'remove') {
        $tags = array_values(array_filter($tags, fn($t) => $t !== $tag));
    }

    $meta['tags'] = $tags;
    $contact->update(['meta' => $meta]);

    return true; // Continua para próximo nó
}
```

---

## 5. Controllers e Rotas

### FunnelController (autenticado)

**File:** `backend/app/Http/Controllers/API/V1/FunnelController.php`

```
GET    /v1/funnels                     → index()        # Lista funis do tenant
POST   /v1/funnels                     → store()        # Criar funil (draft)
GET    /v1/funnels/{id}                → show()         # Funil + nós + edges
PUT    /v1/funnels/{id}                → update()       # Atualizar nome/status/triggers
DELETE /v1/funnels/{id}                → destroy()      # Deletar funil
PUT    /v1/funnels/{id}/canvas         → saveCanvas()   # Salvar nós + edges (editor)
POST   /v1/funnels/{id}/activate       → activate()     # Ativar funil
POST   /v1/funnels/{id}/pause          → pause()        # Pausar funil
POST   /v1/funnels/{id}/enroll         → enroll()       # Inscrever contato manualmente
GET    /v1/funnels/{id}/executions     → executions()   # Listar execuções do funil
```

### saveCanvas()

Endpoint chave — recebe toda a estrutura do editor de uma vez:

```php
public function saveCanvas(int $id, Request $request)
{
    $funnel = Funnel::findOrFail($id);

    $data = $request->validate([
        'nodes'          => ['required', 'array'],
        'nodes.*.node_id'    => ['required', 'string'],
        'nodes.*.type'       => ['required', 'in:start,message,wait,condition,tag'],
        'nodes.*.label'      => ['nullable', 'string'],
        'nodes.*.config'     => ['nullable', 'array'],
        'nodes.*.position_x' => ['required', 'numeric'],
        'nodes.*.position_y' => ['required', 'numeric'],
        'edges'          => ['required', 'array'],
        'edges.*.edge_id'        => ['required', 'string'],
        'edges.*.source_node_id' => ['required', 'string'],
        'edges.*.target_node_id' => ['required', 'string'],
        'edges.*.label'          => ['nullable', 'string'],
    ]);

    // Replace all nodes and edges (full sync)
    DB::transaction(function () use ($funnel, $data) {
        FunnelNode::where('funnel_id', $funnel->id)->delete();
        FunnelEdge::where('funnel_id', $funnel->id)->delete();

        foreach ($data['nodes'] as $node) {
            FunnelNode::create(['funnel_id' => $funnel->id] + $node);
        }
        foreach ($data['edges'] as $edge) {
            FunnelEdge::create(['funnel_id' => $funnel->id] + $edge);
        }
    });

    return ApiResponse::success($funnel->fresh()->load(['nodes', 'edges']), 'Canvas salvo');
}
```

### enroll()

```php
public function enroll(int $id, Request $request)
{
    $funnel = Funnel::findOrFail($id);
    $data = $request->validate([
        'contact_id' => ['required', 'integer', 'exists:contacts,id'],
    ]);

    $contact = Contact::findOrFail($data['contact_id']);
    $tenantId = auth()->user()->tenant_id;

    // Cancelar execução ativa se existir
    FunnelExecution::where('contact_id', $contact->id)
        ->whereIn('status', ['running', 'waiting'])
        ->update(['status' => 'cancelled']);

    // Encontrar nó start
    $startNode = FunnelNode::where('funnel_id', $funnel->id)
        ->where('type', 'start')
        ->first();

    if (! $startNode) {
        return ApiResponse::error('Funil não tem nó de início', [], 422);
    }

    // Buscar/criar conversa
    $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
        ['tenant_id' => $tenantId, 'phone' => $contact->phone],
        ['contact_id' => $contact->id, 'channel' => 'whatsapp', 'status' => 'bot']
    );

    $execution = FunnelExecution::create([
        'tenant_id'       => $tenantId,
        'funnel_id'       => $funnel->id,
        'contact_id'      => $contact->id,
        'conversation_id' => $conversation->id,
        'current_node_id' => $startNode->node_id,
        'status'          => 'running',
        'started_at'      => now(),
    ]);

    // Avançar imediatamente do nó start
    app(FunnelEngineService::class)->advance($execution);

    return ApiResponse::success($execution->fresh(), 'Contato inscrito no funil');
}
```

---

## 6. Frontend — Editor Vue Flow

### Dependência

```bash
npm install @vue-flow/core @vue-flow/background @vue-flow/controls @vue-flow/minimap
```

### Páginas

**`/funnels`** — Lista de funis
- Tabela: Nome, Status (draft/active/paused), Contatos ativos, Criado em
- Botões: Novo funil, Ativar/Pausar, Editar, Deletar

**`/funnels/create`** e **`/funnels/:id/edit`** — Editor Vue Flow

### Layout do Editor

```
┌──────────────────────────────────────────────────────────┐
│ Header: [← Voltar] Nome do funil [Salvar] [Ativar]      │
├──────────┬──────────────────────────────┬────────────────┤
│ Toolbar  │                              │ Properties     │
│          │                              │ Panel          │
│ 📨 Msg   │     Vue Flow Canvas          │                │
│ ⏱ Espera │     (drag, zoom, pan)        │ [Editar nó     │
│ 🔀 Cond  │                              │  selecionado]  │
│ 🏷 Tag   │                              │                │
│          │                              │                │
│          │                              │                │
├──────────┴──────────────────────────────┴────────────────┤
│ Footer: Triggers config [+ Adicionar trigger]            │
└──────────────────────────────────────────────────────────┘
```

### Toolbar (coluna esquerda, 120px)

Blocos arrastáveis que o usuário arrasta para o canvas. Cada bloco tem ícone + label.

Implementação: `@dragstart` no toolbar item seta `dataTransfer`. `@drop` no canvas cria o nó na posição do drop.

### Properties Panel (coluna direita, 300px)

Ao clicar num nó no canvas, o painel mostra o formulário de configuração:

- **Mensagem:** textarea para texto OU select de template + mapping de variáveis
- **Espera:** input numérico + select de unidade (minutos/horas/dias)
- **Condição:** select tipo (respondeu/keyword/timeout) + campo específico
- **Tag:** select ação (adicionar/remover) + input tag

### Triggers (footer)

Lista de triggers configurados para o funil:
- Cada trigger: select tipo (keyword/default) + input pattern
- Botão "+ Adicionar trigger"
- Validação: apenas 1 funil pode ser default por tenant

### Custom Nodes Vue Flow

Cada tipo de nó tem um componente Vue customizado:

```vue
<!-- FunnelNodeMessage.vue -->
<template>
  <div class="funnel-node funnel-node-message" @click="$emit('select')">
    <Handle type="target" :position="Position.Top" />
    <div class="node-header">📨 Mensagem</div>
    <div class="node-body">{{ truncate(data.config?.text, 40) }}</div>
    <Handle type="source" :position="Position.Bottom" />
  </div>
</template>
```

Para nó de **Condição**, tem 2 handles de saída:

```vue
<!-- FunnelNodeCondition.vue -->
<Handle type="source" :position="Position.Bottom" id="yes" style="left:30%" />
<Handle type="source" :position="Position.Bottom" id="no" style="left:70%" />
```

### Salvar Canvas

Ao clicar "Salvar", serializa todos os nós e edges do Vue Flow e faz `PUT /funnels/{id}/canvas`:

```ts
async function saveCanvas() {
  const nodes = vueFlowInstance.getNodes().map(n => ({
    node_id: n.id,
    type: n.type,
    label: n.data.label,
    config: n.data.config,
    position_x: n.position.x,
    position_y: n.position.y,
  }))
  const edges = vueFlowInstance.getEdges().map(e => ({
    edge_id: e.id,
    source_node_id: e.source,
    target_node_id: e.target,
    label: e.label,
  }))
  await put(`/funnels/${funnelId}/canvas`, { nodes, edges })
}
```

---

## 7. Arquivos

### Backend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `database/migrations/xxx_create_funnels_table.php` | Tabela de funis |
| `database/migrations/xxx_create_funnel_nodes_table.php` | Nós do funil |
| `database/migrations/xxx_create_funnel_edges_table.php` | Conexões entre nós |
| `database/migrations/xxx_create_funnel_executions_table.php` | Execuções por contato |
| `app/Models/Funnel.php` | Model com tenant scope |
| `app/Models/FunnelNode.php` | Model sem tenant scope |
| `app/Models/FunnelEdge.php` | Model sem tenant scope |
| `app/Models/FunnelExecution.php` | Model com tenant scope |
| `app/Services/Funnel/FunnelEngineService.php` | Engine de execução |
| `app/Services/Funnel/FunnelTriggerService.php` | Match de triggers |
| `app/Http/Controllers/API/V1/FunnelController.php` | CRUD + canvas + enroll |
| `app/Console/Commands/FunnelTickCommand.php` | Cron tick a cada minuto |
| `app/Jobs/StartFunnelExecutionJob.php` | Inicia execução async |
| `app/Jobs/FunnelConditionCheckJob.php` | Reavalia condição após resposta |

### Backend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `routes/api.php` | Adicionar rotas de funis |
| `routes/console.php` | Registrar FunnelTickCommand no scheduler |
| `app/Http/Controllers/API/V1/WhatsAppWebhookController.php` | Integrar trigger system |

### Frontend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `src/pages/funnels/Index.vue` | Lista de funis |
| `src/pages/funnels/Editor.vue` | Editor Vue Flow |
| `src/components/funnels/FunnelNodeStart.vue` | Nó start |
| `src/components/funnels/FunnelNodeMessage.vue` | Nó mensagem |
| `src/components/funnels/FunnelNodeWait.vue` | Nó espera |
| `src/components/funnels/FunnelNodeCondition.vue` | Nó condição |
| `src/components/funnels/FunnelNodeTag.vue` | Nó tag |
| `src/components/funnels/NodePropertiesPanel.vue` | Painel de propriedades |
| `src/components/funnels/FunnelToolbar.vue` | Toolbar de blocos |
| `src/components/funnels/TriggerConfig.vue` | Config de triggers |

### Frontend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `src/router/index.ts` | Adicionar rotas /funnels |
| `src/components/layout/AppSidebar.vue` | Adicionar "Funis" na nav |
| `package.json` | Adicionar @vue-flow/* dependencies |

---

## 8. Out of Scope

- A/B testing de mensagens no funil
- Métricas/analytics por funil (taxa de conversão por nó)
- Duplicar funil
- Importar/exportar funil como JSON
- Nó de "IA responde" (usa ChatAiService — seria um nó adicional futuro)
- Nó de "transferir para humano" (muda status da conversa)
- Limite de execuções simultâneas por funil
- Webhook de saída (notificar sistema externo)
