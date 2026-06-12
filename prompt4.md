# Prompt — Fase 6: Relatórios e Dashboard

Cole esse prompt no chat do SOLO Coder após aprovação da Fase 5.

---

## Texto do Prompt

```
Leia README.md, .trae/rules.md e as skills antes de começar.
Skills obrigatórias para esta fase:
  - .trae/skills/api-response.md
  - .trae/skills/tabler-ui.md
  - .trae/skills/multitenancy.md
  - .trae/skills/use-api.md

A Fase 5 foi aprovada. Iniciando Fase 6 — Relatórios e Dashboard.
Esta é a fase final. Ao terminar, chame o reviewer com escopo completo do projeto.

---

## FASE 6 — Relatórios e Dashboard

### 6.1 DashboardController

app/Http/Controllers/API/V1/DashboardController.php

GET /api/v1/dashboard/stats → stats()

Retornar tudo em uma única query para evitar N+1:

```php
public function stats(): JsonResponse
{
    $tenantId = auth()->user()->tenant_id;
    $now      = now();
    $last30   = $now->copy()->subDays(30);

    // KPIs principais
    $totalCampaigns  = Campaign::count();
    $activeCampaigns = Campaign::whereIn('status', ['running', 'processing'])->count();

    $dispatches30 = CampaignDispatch::where('created_at', '>=', $last30);
    $sent30       = (clone $dispatches30)->whereIn('status', ['sent','delivered','read'])->count();
    $delivered30  = (clone $dispatches30)->whereIn('status', ['delivered','read'])->count();
    $failed30     = (clone $dispatches30)->where('status', 'failed')->count();
    $total30      = (clone $dispatches30)->count();

    $deliveryRate = $total30 > 0
        ? round(($delivered30 / $total30) * 100, 1)
        : 0;

    // Créditos
    $tenant  = auth()->user()->tenant;
    $credits = $tenant->credits_balance;

    // Envios por dia (últimos 14 dias) — para sparkline/gráfico
    $dailySent = CampaignDispatch::selectRaw(
            'DATE(created_at) as date, COUNT(*) as total'
        )
        ->where('created_at', '>=', $now->copy()->subDays(14))
        ->whereIn('status', ['sent','delivered','read'])
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->keyBy('date');

    // Preencher dias sem envio com zero
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $date = $now->copy()->subDays($i)->format('Y-m-d');
        $days[] = [
            'date'  => $date,
            'label' => $now->copy()->subDays($i)->format('d/M'),
            'total' => $dailySent->get($date)?->total ?? 0,
        ];
    }

    // Distribuição por canal (últimos 30 dias)
    $byChannel = Campaign::where('created_at', '>=', $last30)
        ->selectRaw('type, COUNT(*) as total')
        ->groupBy('type')
        ->pluck('total', 'type');

    // Últimas 5 campanhas
    $recentCampaigns = Campaign::with('contactList')
        ->orderByDesc('created_at')
        ->limit(5)
        ->get(['id','name','type','status','sent_count','failed_count','created_at']);

    // Últimas 5 transações de crédito
    $recentTransactions = CreditTransaction::orderByDesc('created_at')
        ->limit(5)
        ->get(['type','amount','balance_after','description','created_at']);

    return ApiResponse::success([
        'kpis' => [
            'total_campaigns'   => $totalCampaigns,
            'active_campaigns'  => $activeCampaigns,
            'sent_30d'          => $sent30,
            'delivered_30d'     => $delivered30,
            'failed_30d'        => $failed30,
            'delivery_rate_30d' => $deliveryRate,
            'credits_balance'   => $credits,
        ],
        'daily_sent'          => $days,
        'by_channel'          => $byChannel,
        'recent_campaigns'    => $recentCampaigns,
        'recent_transactions' => $recentTransactions,
    ]);
}
```

---

### 6.2 ReportController

app/Http/Controllers/API/V1/ReportController.php

GET /api/v1/reports/campaigns         → campaigns()
GET /api/v1/reports/campaigns/{id}    → campaign()
GET /api/v1/reports/campaigns/{id}/export → export()
GET /api/v1/reports/credits           → credits()

#### campaigns() — Relatório geral de campanhas
Parâmetros: ?from=&to=&type=&status=&search=

Retorna paginado (20/página):
  id, name, type, status, contact_list name,
  sent_count, failed_count, estimated_contacts,
  delivery_rate (sent/estimated * 100),
  started_at, completed_at, duration (em minutos)

Ordenação padrão: completed_at DESC (mais recentes primeiro)

#### campaign() — Relatório detalhado de uma campanha
Retorna:
```json
{
  "campaign": { ...todos os campos },
  "stats": {
    "total":         1200,
    "sent":          1180,
    "delivered":     1050,
    "failed":        20,
    "read":          320,
    "pending":       0,
    "delivery_rate": 89.0,
    "read_rate":     27.1
  },
  "timeline": [
    { "hour": "2024-01-15 10:00", "sent": 240, "delivered": 220, "failed": 5 },
    ...
  ],
  "errors": [
    { "message": "Invalid phone number", "count": 12 },
    { "message": "Carrier rejected", "count": 8 }
  ],
  "dispatches_sample": [ ...10 primeiros dispatches ]
}
```

timeline: agrupa dispatches por hora do sent_at (últimas 24h da campanha)
errors: agrupa error_message e conta ocorrências (top 5)

#### export() — CSV dos dispatches
Streamed response (não carrega tudo na memória):

```php
public function export(int $id): StreamedResponse
{
    $campaign = Campaign::findOrFail($id);

    return response()->stream(function () use ($campaign) {
        $handle = fopen('php://output', 'w');

        // BOM UTF-8 para Excel brasileiro
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($handle, [
            'Nome', 'Telefone', 'Email', 'Status',
            'ID Externo', 'Enviado em', 'Entregue em', 'Erro'
        ], ';');

        CampaignDispatch::where('campaign_id', $campaign->id)
            ->with('contact:id,name,email')
            ->orderBy('id')
            ->chunk(500, function ($dispatches) use ($handle) {
                foreach ($dispatches as $d) {
                    fputcsv($handle, [
                        $d->contact?->name     ?? '',
                        $d->phone,
                        $d->contact?->email    ?? '',
                        $d->status,
                        $d->external_message_id ?? '',
                        $d->sent_at?->format('d/m/Y H:i') ?? '',
                        $d->delivered_at?->format('d/m/Y H:i') ?? '',
                        $d->error_message ?? '',
                    ], ';');
                }
            });

        fclose($handle);
    }, 200, [
        'Content-Type'        => 'text/csv; charset=UTF-8',
        'Content-Disposition' => "attachment; filename=\"campanha-{$id}.csv\"",
        'Cache-Control'       => 'no-cache',
    ]);
}
```

#### credits() — Extrato de créditos
Parâmetros: ?from=&to=&type=debit|credit

Retorna paginado (30/página):
  type, amount, balance_after, reference_type, description, created_at
Ordenado: created_at DESC

Totalizadores no topo:
  total_debited (últimos 30 dias), total_credited (últimos 30 dias),
  current_balance, transactions_count

---

### 6.3 Rotas API

Em routes/api.php, dentro de auth:sanctum:

Route::get('dashboard/stats', [DashboardController::class, 'stats']);

Route::prefix('reports')->group(function () {
    Route::get('campaigns',          [ReportController::class, 'campaigns']);
    Route::get('campaigns/{id}',     [ReportController::class, 'campaign']);
    Route::get('campaigns/{id}/export', [ReportController::class, 'export']);
    Route::get('credits',            [ReportController::class, 'credits']);
});

---

### 6.4 Frontend — Dashboard Completo

#### pages/dashboard/Index.vue

Substituir o placeholder da Fase 1 pela implementação completa.

Layout:

```
┌─────────────────────────────────────────────────────────┐
│  Page header: "Dashboard"  +  período: "Últimos 30 dias" │
├──────────┬──────────┬──────────┬──────────┬─────────────┤
│ Campanhas│ Enviados │  Taxa    │  Falhas  │  Créditos   │
│  total   │  30d     │ entrega  │   30d    │  saldo      │
├──────────┴──────────┴──────────┴──────────┴─────────────┤
│                                                         │
│   Gráfico de envios por dia (14 dias)     │ Canais      │
│                                           │ (donut)     │
│                                           │             │
├───────────────────────────┬───────────────┴─────────────┤
│  Últimas campanhas        │  Extrato de créditos        │
│                           │                             │
└───────────────────────────┴─────────────────────────────┘
```

#### KPI Cards (5 cards — row de col-sm-6 col-lg)

```html
<div class="col-sm-6 col-lg">
  <div class="card">
    <div class="card-body">
      <div class="d-flex align-items-center">
        <div class="subheader">Total de campanhas</div>
      </div>
      <div class="d-flex align-items-baseline mt-3 mb-2">
        <div class="h1 mb-0 me-2">{{ kpis.total_campaigns }}</div>
        <div class="me-auto">
          <span class="badge bg-primary-lt text-primary"
            v-if="kpis.active_campaigns > 0">
            {{ kpis.active_campaigns }} ativas
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Enviados 30d -->
<div class="col-sm-6 col-lg">
  <div class="card">
    <div class="card-body">
      <div class="subheader">Enviados (30 dias)</div>
      <div class="d-flex align-items-baseline mt-3 mb-2">
        <div class="h1 mb-0 me-2">
          {{ kpis.sent_30d.toLocaleString('pt-BR') }}
        </div>
      </div>
      <div class="progress progress-sm mt-2">
        <div class="progress-bar bg-primary"
          :style="`width: ${kpis.delivery_rate_30d}%`"></div>
      </div>
      <div class="d-flex mt-1">
        <div class="text-muted small">
          Taxa de entrega: {{ kpis.delivery_rate_30d }}%
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Taxa de entrega -->
<div class="col-sm-6 col-lg">
  <div class="card">
    <div class="card-body">
      <div class="subheader">Taxa de entrega</div>
      <div class="d-flex align-items-baseline mt-3 mb-2">
        <div class="h1 mb-0 me-2"
          :class="{
            'text-success': kpis.delivery_rate_30d >= 90,
            'text-warning': kpis.delivery_rate_30d >= 70 && kpis.delivery_rate_30d < 90,
            'text-danger':  kpis.delivery_rate_30d < 70,
          }">
          {{ kpis.delivery_rate_30d }}%
        </div>
      </div>
      <div class="text-muted small">
        {{ kpis.delivered_30d.toLocaleString('pt-BR') }} entregues
        de {{ kpis.sent_30d.toLocaleString('pt-BR') }} enviados
      </div>
    </div>
  </div>
</div>

<!-- Falhas -->
<div class="col-sm-6 col-lg">
  <div class="card">
    <div class="card-body">
      <div class="subheader">Falhas (30 dias)</div>
      <div class="d-flex align-items-baseline mt-3 mb-2">
        <div class="h1 mb-0 me-2"
          :class="kpis.failed_30d > 0 ? 'text-danger' : ''">
          {{ kpis.failed_30d.toLocaleString('pt-BR') }}
        </div>
      </div>
      <div class="text-muted small">
        <span v-if="kpis.failed_30d === 0" class="text-success">
          <i class="ti ti-circle-check me-1"></i>Nenhuma falha
        </span>
        <span v-else>
          <i class="ti ti-alert-triangle me-1 text-danger"></i>
          Verificar dispatches com erro
        </span>
      </div>
    </div>
  </div>
</div>

<!-- Créditos -->
<div class="col-sm-6 col-lg">
  <div class="card">
    <div class="card-body">
      <div class="subheader">Créditos disponíveis</div>
      <div class="d-flex align-items-baseline mt-3 mb-2">
        <div class="h1 mb-0 me-2"
          :class="{
            'text-success': kpis.credits_balance > 500,
            'text-warning': kpis.credits_balance > 100 && kpis.credits_balance <= 500,
            'text-danger':  kpis.credits_balance <= 100,
          }">
          {{ kpis.credits_balance.toLocaleString('pt-BR') }}
        </div>
      </div>
      <div class="text-muted small">
        <i class="ti ti-bolt me-1"></i>créditos no saldo
      </div>
    </div>
  </div>
</div>
```

#### Gráfico de Envios (14 dias)

Usar ApexCharts (instalar: npm install apexcharts vue3-apexcharts).

```typescript
// Registrar globalmente em main.ts:
import VueApexCharts from 'vue3-apexcharts'
app.use(VueApexCharts)
```

```html
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Envios por dia</h3>
    <div class="card-options">
      <span class="text-muted small">Últimos 14 dias</span>
    </div>
  </div>
  <div class="card-body">
    <apexchart
      type="area"
      height="220"
      :options="chartOptions"
      :series="chartSeries">
    </apexchart>
  </div>
</div>
```

```typescript
const chartOptions = computed(() => ({
  chart: {
    toolbar: { show: false },
    sparkline: { enabled: false },
    fontFamily: 'inherit',
  },
  stroke:     { curve: 'smooth', width: 2 },
  fill:       { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0 } },
  colors:     ['#0064ff'],
  xaxis: {
    categories: dailySent.value.map(d => d.label),
    labels:     { style: { fontSize: '11px' } },
  },
  yaxis:   { labels: { formatter: (v: number) => v.toLocaleString('pt-BR') } },
  tooltip: { y: { formatter: (v: number) => `${v.toLocaleString('pt-BR')} envios` } },
  grid:    { borderColor: '#e4e8ef', strokeDashArray: 4 },
  dataLabels: { enabled: false },
}))

const chartSeries = computed(() => [{
  name: 'Enviados',
  data: dailySent.value.map(d => d.total),
}])
```

#### Donut de Canais

```html
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Por canal</h3>
  </div>
  <div class="card-body">
    <apexchart
      type="donut"
      height="220"
      :options="donutOptions"
      :series="donutSeries">
    </apexchart>
  </div>
</div>
```

```typescript
const donutOptions = computed(() => ({
  labels:  ['SMS', 'Voz', 'Email'],
  colors:  ['#0064ff', '#4299e1', '#0ca678'],
  legend:  { position: 'bottom', fontFamily: 'inherit' },
  plotOptions: { pie: { donut: { size: '65%' } } },
  dataLabels:  { enabled: false },
  chart:   { fontFamily: 'inherit' },
}))

const donutSeries = computed(() => [
  byChannel.value.sms   ?? 0,
  byChannel.value.voice ?? 0,
  byChannel.value.email ?? 0,
])
```

#### Card "Últimas Campanhas"

```html
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Últimas campanhas</h3>
    <div class="card-options">
      <a class="btn btn-ghost-secondary btn-sm"
        @click="router.push('/reports/campaigns')">
        Ver todas <i class="ti ti-arrow-right ms-1"></i>
      </a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter table-hover card-table">
      <thead>
        <tr>
          <th>Campanha</th>
          <th>Canal</th>
          <th>Status</th>
          <th>Enviados</th>
          <th>Entrega</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="c in recentCampaigns" :key="c.id"
          class="cursor-pointer"
          @click="router.push(`/reports/campaigns/${c.id}`)">
          <td>{{ c.name }}</td>
          <td>
            <span class="badge" :class="channelBadge(c.type)">
              {{ c.type.toUpperCase() }}
            </span>
          </td>
          <td>
            <span class="badge" :class="`bg-${statusColor(c.status)}`">
              {{ statusLabel(c.status) }}
            </span>
          </td>
          <td>{{ c.sent_count.toLocaleString('pt-BR') }}</td>
          <td>
            <span v-if="c.estimated_contacts > 0">
              {{ Math.round((c.sent_count / c.estimated_contacts) * 100) }}%
            </span>
            <span v-else class="text-muted">—</span>
          </td>
        </tr>
        <tr v-if="!recentCampaigns.length">
          <td colspan="5" class="text-center text-muted py-3">
            Nenhuma campanha ainda.
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
```

#### Card "Extrato de Créditos"

```html
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Créditos recentes</h3>
    <div class="card-options">
      <a class="btn btn-ghost-secondary btn-sm"
        @click="router.push('/reports/credits')">
        Ver extrato <i class="ti ti-arrow-right ms-1"></i>
      </a>
    </div>
  </div>
  <div class="list-group list-group-flush">
    <div class="list-group-item"
      v-for="tx in recentTransactions" :key="tx.id">
      <div class="row align-items-center">
        <div class="col-auto">
          <span class="avatar avatar-sm rounded"
            :class="tx.type === 'credit' ? 'bg-success-lt' : 'bg-danger-lt'">
            <i class="ti"
              :class="tx.type === 'credit' ? 'ti-plus text-success' : 'ti-minus text-danger'">
            </i>
          </span>
        </div>
        <div class="col">
          <div class="text-truncate small fw-medium">{{ tx.description }}</div>
          <div class="text-muted" style="font-size: .7rem">
            {{ formatDate(tx.created_at) }}
          </div>
        </div>
        <div class="col-auto">
          <span :class="tx.type === 'credit' ? 'text-success' : 'text-danger'"
            class="fw-medium small">
            {{ tx.type === 'credit' ? '+' : '-' }}{{ tx.amount }}
          </span>
          <div class="text-muted" style="font-size: .7rem; text-align: right">
            saldo: {{ tx.balance_after.toLocaleString('pt-BR') }}
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
```

---

### 6.5 Frontend — Página de Relatório de Campanhas

#### pages/reports/Campaigns.vue

Page header: "Relatório de Campanhas"

Filtros em row:
  - Date range: [De] [Até] (inputs date)
  - Select canal: Todos | SMS | Voz | Email
  - Select status: Todos | Rascunho | ... | Concluído
  - Input busca por nome

Tabela com colunas:
  Nome | Canal | Status | Contatos | Enviados | Entregues | Taxa | Duração | Ações

Taxa colorida:
  >= 90%: text-success
  >= 70%: text-warning
  < 70%:  text-danger

Ações:
  - Ver detalhes → /reports/campaigns/{id}
  - Exportar CSV → GET /reports/campaigns/{id}/export (download direto)

Paginação nativa Tabler (20/página).

---

### 6.6 Frontend — Página de Detalhe do Relatório

#### pages/reports/CampaignDetail.vue

Page header:
  Nome da campanha
  Badges: canal + status
  Botões: [Exportar CSV] [Duplicar campanha]

Row de 4 KPI cards:
  Total dispatches | Entregues | Taxa de entrega | Taxa de leitura

Gráfico timeline (barras agrupadas: enviados + entregues + falhas por hora):
```typescript
const timelineOptions = {
  chart:  { type: 'bar', stacked: false, toolbar: { show: false } },
  colors: ['#0064ff', '#0ca678', '#d63939'],
  xaxis:  { categories: timeline.map(t => t.hour) },
  legend: { position: 'top' },
}
const timelineSeries = [
  { name: 'Enviados',   data: timeline.map(t => t.sent) },
  { name: 'Entregues',  data: timeline.map(t => t.delivered) },
  { name: 'Falhas',     data: timeline.map(t => t.failed) },
]
```

Card "Erros mais comuns" (só se errors.length > 0):
  Lista com badge de contagem e mensagem de erro.

Tabela "Amostra de dispatches" (primeiros 10):
  Contato | Telefone | Status | Enviado em | Entregue em | Erro
  Badge de status por linha.
  Link "Ver todos os dispatches" → abre modal com tabela paginada completa.

---

### 6.7 Frontend — Página de Extrato de Créditos

#### pages/reports/Credits.vue

Page header: "Extrato de Créditos"

Row de 3 cards resumo:
  Saldo atual | Debitado (30d) | Creditado (30d)

Filtros: [De] [Até] + Select tipo: Todos | Débito | Crédito

Tabela:
  Tipo | Descrição | Valor | Saldo após | Data
  Tipo: badge verde (crédito) / vermelho (débito)
  Valor: +X (verde) / -X (vermelho)

Paginação (30/página).

---

### 6.8 Rotas do Frontend

Adicionar em router/index.ts:

```typescript
// Dashboard
{ path: '/dashboard', component: () => import('@/pages/dashboard/Index.vue'),
  meta: { title: 'Dashboard' } },

// Relatórios
{ path: '/reports/campaigns', component: () => import('@/pages/reports/Campaigns.vue'),
  meta: { title: 'Relatórios' } },
{ path: '/reports/campaigns/:id', component: () => import('@/pages/reports/CampaignDetail.vue'),
  meta: { title: 'Detalhe da Campanha' } },
{ path: '/reports/credits', component: () => import('@/pages/reports/Credits.vue'),
  meta: { title: 'Extrato de Créditos' } },
```

Atualizar sidebar: item "Relatórios" → /reports/campaigns

---

### 6.9 Store de Relatórios

stores/report.ts:
  state: dashboardStats, campaigns, currentCampaign, credits, isLoading
  actions:
    fetchDashboard()
    fetchCampaigns(filters)
    fetchCampaign(id)
    exportCampaign(id)    → window.location.href = `/api/v1/reports/campaigns/${id}/export`
    fetchCredits(filters)

---

### 6.10 Revisão Final — Escopo Completo do Projeto

Chamar agent `reviewer` com escopo de TODAS as fases (1 a 6):

FASE 1 — Base:
  [ ] Auth Sanctum funcionando (login, logout, guard)
  [ ] ApiResponse em todos os controllers
  [ ] GlobalScope em todos os models de tenant
  [ ] Settings globais sempre com withoutGlobalScopes()

FASE 2 — Admin:
  [ ] Nenhum endpoint retorna valor de api_key (apenas has_api_key)
  [ ] Todas as rotas /admin com middleware superadmin
  [ ] API keys encrypted no banco e decrypted na leitura

FASE 3 — Contatos:
  [ ] ProcessImportJob com withoutGlobalScopes() + tenant_id explícito
  [ ] contact_count atualizado após import
  [ ] CreditService::debit() com DB::transaction + lockForUpdate
  [ ] Saldo nunca negativo

FASE 4 — Campanhas:
  [ ] Nenhuma transição de status fora do CampaignStateMachine
  [ ] ProcessCampaignJob verifica saldo por envio (não uma vez só)
  [ ] Crédito debitado APÓS envio bem-sucedido
  [ ] Auto-save com debounce (não por tecla)
  [ ] PhonePreview sanitiza HTML (DOMPurify)

FASE 5 — IA:
  [ ] ai_generation criado ANTES da chamada Grok
  [ ] Tokens + custo registrados após geração
  [ ] ElevenLabs: saldo verificado antes, áudio em storage, URL temporária
  [ ] parseVariations e parseAnalysis tratam JSON inválido do Grok

FASE 6 — Relatórios:
  [ ] export() usa chunk() (não carrega tudo na memória)
  [ ] BOM UTF-8 no CSV para Excel brasileiro
  [ ] DashboardController sem N+1 (queries otimizadas)
  [ ] ApexCharts instalado e registrado globalmente

SEGURANÇA GERAL:
  [ ] Nenhuma credencial hardcoded
  [ ] Nenhum dado de outro tenant acessível
  [ ] Todos os endpoints protegidos com auth:sanctum
  [ ] Todos os endpoints admin protegidos com superadmin

Apresentar checklist completo e score final por categoria.
Listar TODOS os arquivos criados/modificados nas Fases 5 e 6.

---

## Entrega Final

Esta é a última fase do desenvolvimento base do CampaignAI.

Após aprovação do reviewer, entregar:
  1. Lista completa de todos os arquivos do projeto
  2. Comandos de setup do zero:
       php artisan migrate --seed
       npm run build
       php artisan optimize
       php artisan queue:work
  3. Credenciais padrão do superadmin
  4. Checklist de configurações pós-deploy:
       [ ] Configurar Infobip (API Key + base URL + senders)
       [ ] Configurar Grok (API Key + modelo)
       [ ] Configurar ElevenLabs (API Key + precificação)
       [ ] Sincronizar vozes ElevenLabs
       [ ] Criar primeiro tenant
       [ ] Adicionar créditos ao tenant
       [ ] Configurar scheduler (cron) para campanhas agendadas
       [ ] Configurar queue worker como serviço (Supervisor)
```