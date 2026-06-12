# Skill: Tabler UI

## Quando Usar
Em todo componente Vue que precisa de elementos visuais.
Tabler UI é a única biblioteca de componentes permitida.
Nunca criar componentes customizados para o que o Tabler já oferece.

## Instalação

```bash
npm install @tabler/core
```

Import no frontend (main.ts):
```typescript
import '@tabler/core/dist/css/tabler.min.css'
import '@tabler/icons-webfont/dist/tabler-icons.min.css'
import '@tabler/core/dist/js/tabler.min.js'
```

## Override da Paleta

`resources/css/app.css`:
```css
:root {
  --tblr-primary:        #0064ff;
  --tblr-primary-rgb:    0, 100, 255;
  --tblr-primary-lt:     rgba(0, 100, 255, 0.08);
  --tblr-body-bg:        #f8f8f8;
  --tblr-border-color:   #e4e8ef;
  --tblr-sidebar-width:  240px;

  /* Dark sidebar */
  --tblr-navbar-bg:      #080c25;
  --tblr-navbar-color:   rgba(255, 255, 255, 0.70);
}
```

---

## Componentes — Referência Rápida

### Layout de Página
```html
<div class="page">
  <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
    <!-- AppSidebar -->
  </aside>
  <div class="page-wrapper">
    <!-- AppTopbar -->
    <div class="page-body">
      <div class="container-xl">
        <!-- <router-view /> conteúdo da página -->
      </div>
    </div>
    <footer class="footer footer-transparent d-print-none">...</footer>
  </div>
</div>
```

### Layout Vertical (data-bs-theme)
```html
<aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
  <div class="container-fluid">
    <div class="navbar-brand d-flex align-items-center justify-content-between">
      <a class="navbar-brand navbar-brand-autodark">
        <span class="text-white fw-bold">CampaignAI</span>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu">
        <span class="navbar-toggler-icon"></span>
      </button>
    </div>
    <div class="collapse navbar-collapse" id="sidebar-menu">
      <ul class="navbar-nav pt-lg-3">
        <li class="nav-item">
          <a class="nav-link active">
            <span class="nav-link-icon"><i class="ti ti-dashboard"></i></span>
            <span class="nav-link-title">Dashboard</span>
          </a>
        </li>
      </ul>
    </div>
  </div>
</aside>
```

### Card
```html
<!-- Card simples -->
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Título</h3>
    <div class="card-options">
      <button class="btn btn-sm btn-ghost-secondary">Ação</button>
    </div>
  </div>
  <div class="card-body">
    Conteúdo
  </div>
  <div class="card-footer">
    <button class="btn btn-primary">Salvar</button>
  </div>
</div>

<!-- Card com status color -->
<div class="card border-top border-primary border-3">...</div>
```

### Botões
```html
<button class="btn btn-primary">Primário</button>
<button class="btn btn-secondary">Secundário</button>
<button class="btn btn-danger">Perigo</button>
<button class="btn btn-success">Sucesso</button>
<button class="btn btn-ghost-secondary">Ghost</button>
<button class="btn btn-outline-primary">Outline</button>

<!-- Com ícone -->
<button class="btn btn-primary">
  <i class="ti ti-send me-1"></i> Enviar
</button>

<!-- Tamanhos -->
<button class="btn btn-primary btn-sm">Pequeno</button>
<button class="btn btn-primary btn-lg">Grande</button>

<!-- Loading (Vue) -->
<button class="btn btn-primary" :disabled="isLoading">
  <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
  {{ isLoading ? 'Salvando...' : 'Salvar' }}
</button>
```

### Formulários
```html
<!-- Campo de texto -->
<div class="mb-3">
  <label class="form-label required">Nome da campanha</label>
  <input type="text" class="form-control" v-model="form.name"
    :class="{ 'is-invalid': errors.name }"
    placeholder="Ex: Promoção Black Friday">
  <div class="invalid-feedback" v-if="errors.name">
    {{ errors.name[0] }}
  </div>
  <small class="form-hint">Usado internamente para identificar a campanha.</small>
</div>

<!-- Select -->
<div class="mb-3">
  <label class="form-label required">Canal</label>
  <select class="form-select" v-model="form.channel">
    <option value="">Selecione...</option>
    <option value="sms">SMS</option>
    <option value="voice">Torpedo de Voz</option>
    <option value="email">Email Marketing</option>
  </select>
</div>

<!-- Textarea -->
<div class="mb-3">
  <label class="form-label">Mensagem</label>
  <textarea class="form-control" rows="4" v-model="form.content"></textarea>
  <small class="form-hint text-end d-block">{{ form.content?.length ?? 0 }}/160</small>
</div>

<!-- Toggle switch -->
<div class="mb-3">
  <label class="form-check form-switch">
    <input class="form-check-input" type="checkbox" v-model="form.active">
    <span class="form-check-label">Ativo</span>
  </label>
</div>

<!-- Input com prefixo -->
<div class="input-group mb-3">
  <span class="input-group-text">R$</span>
  <input type="number" class="form-control" v-model="form.price">
</div>
```

### Badges
```html
<span class="badge bg-primary">SMS</span>
<span class="badge bg-success">Entregue</span>
<span class="badge bg-danger">Falhou</span>
<span class="badge bg-warning text-dark">Agendado</span>
<span class="badge bg-secondary">Rascunho</span>
<span class="badge bg-info">Processando</span>
<span class="badge bg-azure">Email</span>

<!-- Com ponto de status -->
<span class="badge bg-success">
  <span class="badge-dot"></span> Online
</span>

<!-- Badge em Vue com status dinâmico -->
<span :class="`badge bg-${statusColor(campaign.status)}`">
  {{ statusLabel(campaign.status) }}
</span>
```

### Tabelas
```html
<div class="card">
  <div class="card-header">
    <h3 class="card-title">Campanhas</h3>
    <div class="card-options">
      <input class="form-control form-control-sm" type="text"
        v-model="search" placeholder="Buscar...">
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter table-hover card-table">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Canal</th>
          <th>Status</th>
          <th>Envios</th>
          <th class="w-1">Ações</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="campaign in campaigns" :key="campaign.id">
          <td>{{ campaign.name }}</td>
          <td><span class="badge bg-primary">{{ campaign.channel }}</span></td>
          <td><span :class="`badge bg-${statusColor(campaign.status)}`">{{ campaign.status }}</span></td>
          <td>{{ campaign.sent_count }}</td>
          <td>
            <div class="dropdown">
              <button class="btn btn-ghost-secondary btn-icon btn-sm"
                data-bs-toggle="dropdown">
                <i class="ti ti-dots-vertical"></i>
              </button>
              <div class="dropdown-menu dropdown-menu-end">
                <a class="dropdown-item" @click="view(campaign.id)">
                  <i class="ti ti-eye me-2"></i> Ver
                </a>
                <a class="dropdown-item" @click="duplicate(campaign.id)">
                  <i class="ti ti-copy me-2"></i> Duplicar
                </a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" @click="remove(campaign.id)">
                  <i class="ti ti-trash me-2"></i> Excluir
                </a>
              </div>
            </div>
          </td>
        </tr>
        <tr v-if="!campaigns.length">
          <td colspan="5" class="text-center text-muted py-4">
            Nenhuma campanha encontrada.
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
```

### Empty State
```html
<div class="empty">
  <div class="empty-icon"><i class="ti ti-inbox"></i></div>
  <p class="empty-title">Nenhuma campanha ainda</p>
  <p class="empty-subtitle text-muted">
    Crie sua primeira campanha e comece a enviar mensagens.
  </p>
  <div class="empty-action">
    <button class="btn btn-primary" @click="router.push('/campaigns/create')">
      <i class="ti ti-plus me-1"></i> Criar campanha
    </button>
  </div>
</div>
```

### Modal
```html
<!-- Trigger -->
<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalConfirm">
  Confirmar
</button>

<!-- Modal -->
<div class="modal modal-blur fade" id="modalConfirm" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar ação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Tem certeza que deseja continuar?
      </div>
      <div class="modal-footer">
        <button class="btn btn-link link-secondary me-auto"
          data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" @click="confirm">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal controlado por Vue (sem data-bs-toggle) -->
<script setup>
import { ref, onMounted } from 'vue'
import { Modal } from '@tabler/core'

const modalRef = ref(null)
let modal: Modal

onMounted(() => {
  modal = new Modal(modalRef.value)
})

const openModal = () => modal.show()
const closeModal = () => modal.hide()
</script>
```

### Alertas e Toasts
```html
<!-- Alerta inline -->
<div class="alert alert-success" v-if="success">
  <i class="ti ti-check me-2"></i> {{ success }}
</div>

<div class="alert alert-danger" v-if="error">
  <i class="ti ti-alert-circle me-2"></i> {{ error }}
</div>

<!-- Toast programático (via useToast composable) -->
<script setup lang="ts">
import { useToast } from '@/composables/useToast'
const toast = useToast()
toast.success('Ação executada com sucesso')
</script>
```
Observação: o composable useToast utiliza `window.bootstrap.Toast`, fornecido por `@tabler/core/dist/js/tabler.min.js`.

### Sidebar
```html
<aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
  <div class="container-fluid">

    <!-- Logo -->
    <a class="navbar-brand navbar-brand-autodark">
      <span class="text-white fw-bold">CampaignAI</span>
    </a>

    <!-- Nav items -->
    <ul class="navbar-nav pt-lg-3">
      <li class="nav-item">
        <a class="nav-link" :class="{ active: route.path === '/dashboard' }"
          @click="router.push('/dashboard')">
          <span class="nav-link-icon d-md-none d-lg-inline-block">
            <i class="ti ti-dashboard"></i>
          </span>
          <span class="nav-link-title">Dashboard</span>
        </a>
      </li>
    </ul>

    <!-- Seção com título -->
    <ul class="navbar-nav pt-lg-3">
      <li class="nav-item">
        <div class="nav-link-title text-muted small text-uppercase px-3 mb-1">
          Canais
        </div>
      </li>
      <li class="nav-item">
        <a class="nav-link" :class="{ active: route.path.startsWith('/sms') }">
          <span class="nav-link-icon"><i class="ti ti-message"></i></span>
          <span class="nav-link-title">SMS</span>
        </a>
      </li>
    </ul>

  </div>
</aside>
```

### Placeholder / Skeleton
```html
<div class="placeholder-glow">
  <span class="placeholder col-7"></span>
  <span class="placeholder col-4"></span>
  <span class="placeholder col-4"></span>
  <span class="placeholder col-6"></span>
  <span class="placeholder col-8"></span>
</div>
```

### Steps Counter
```html
<ol class="steps steps-counter">
  <li class="active"><a class="step-item">Passo 1</a></li>
  <li><a class="step-item">Passo 2</a></li>
  <li><a class="step-item">Passo 3</a></li>
</ol>
```

### Dicas e Tooltip
```html
<label class="form-label">Campo</label>
<input class="form-control" placeholder="Digite aqui">
<small class="form-hint">Dica do campo com exemplo.</small>

<button class="btn btn-ghost-secondary" data-bs-toggle="tooltip" title="Mais informações">
  <i class="ti ti-info-circle"></i>
</button>
```

### Login (page-center / container-tight)
```html
<div class="page page-center">
  <div class="container-tight py-6">
    <div class="card card-md">
      <div class="card-body">...</div>
    </div>
  </div>
</div>
```

### Stats / KPI Cards
```html
<div class="row row-deck row-cards">
  <div class="col-sm-6 col-lg-3">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="subheader">Total enviado</div>
        </div>
        <div class="h1 mb-3">12.450</div>
        <div class="d-flex mb-2">
          <div>Taxa de entrega</div>
          <div class="ms-auto">
            <span class="text-success d-inline-flex align-items-center lh-1">
              98% <i class="ti ti-trending-up ms-1"></i>
            </span>
          </div>
        </div>
        <div class="progress mb-2" style="height: 4px;">
          <div class="progress-bar bg-primary" style="width: 98%"></div>
        </div>
      </div>
    </div>
  </div>
</div>
```

---

## Ícones Tabler — Referência por Contexto

| Contexto         | Ícone                        |
|------------------|------------------------------|
| Dashboard        | `ti-dashboard`               |
| Campanhas        | `ti-speakerphone`            |
| SMS              | `ti-message`                 |
| Voz              | `ti-phone`                   |
| Email            | `ti-mail`                    |
| Contatos         | `ti-users`                   |
| Templates        | `ti-template`                |
| IA / Gerar       | `ti-sparkles`                |
| Relatórios       | `ti-chart-bar`               |
| Configurações    | `ti-settings`                |
| Adicionar        | `ti-plus`                    |
| Editar           | `ti-pencil`                  |
| Excluir          | `ti-trash`                   |
| Duplicar         | `ti-copy`                    |
| Enviar           | `ti-send`                    |
| Download         | `ti-download`                |
| Upload           | `ti-upload`                  |
| Buscar           | `ti-search`                  |
| Filtrar          | `ti-filter`                  |
| Fechar           | `ti-x`                       |
| Sucesso          | `ti-check`                   |
| Alerta           | `ti-alert-circle`            |
| Info             | `ti-info-circle`             |
| Play             | `ti-player-play`             |
| Pause            | `ti-player-pause`            |
| Créditos         | `ti-bolt`                    |
| Agendado         | `ti-calendar`                |
| Histórico        | `ti-history`                 |

---

## Helpers Vue para Status

```typescript
// composables/useStatus.ts

export const useCampaignStatus = () => {
  const color = (status: string): string => ({
    draft:      'secondary',
    processing: 'warning',
    running:    'info',
    scheduled:  'azure',
    completed:  'success',
    failed:     'danger',
  }[status] ?? 'secondary')

  const label = (status: string): string => ({
    draft:      'Rascunho',
    processing: 'Processando',
    running:    'Enviando',
    scheduled:  'Agendado',
    completed:  'Concluído',
    failed:     'Falhou',
  }[status] ?? status)

  const icon = (status: string): string => ({
    draft:      'ti-pencil',
    processing: 'ti-loader',
    running:    'ti-send',
    scheduled:  'ti-calendar',
    completed:  'ti-check',
    failed:     'ti-x',
  }[status] ?? 'ti-circle')

  return { color, label, icon }
}
```

---

## Nunca Fazer

```html
<!-- ❌ Criar botão customizado quando Tabler já tem -->
<div class="my-custom-btn" @click="...">Salvar</div>

<!-- ❌ Hardcodar cor no template -->
<span style="color: #0064ff">Ativo</span>

<!-- ❌ Usar Bootstrap puro (usar classes Tabler equivalentes) -->
<div class="container"><div class="row"><div class="col-md-6">

<!-- ✅ Usar Tabler -->
<div class="container-xl"><div class="row row-cards"><div class="col-md-6">
```
