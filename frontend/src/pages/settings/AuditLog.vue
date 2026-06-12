<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-.03em;margin:0">Histórico de Auditoria</h2>
        <span style="font-size:.85rem;color:var(--bc-text-muted)">
          Registro imutável de ações sensíveis (LGPD art. 37). Apenas administradores podem visualizar.
        </span>
      </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3" style="border-radius:12px">
      <div class="card-body py-2 d-flex gap-2 flex-wrap align-items-end">
        <div style="min-width:200px">
          <label class="form-label small text-muted">Ação</label>
          <input class="form-control form-control-sm" v-model="filters.action"
                 placeholder="ex: campaign. ou billing.recharge" @keyup.enter="reload()" />
        </div>
        <div style="min-width:160px">
          <label class="form-label small text-muted">Recurso</label>
          <select class="form-select form-select-sm" v-model="filters.resource">
            <option value="">Todos</option>
            <option value="Campaign">Campaign</option>
            <option value="Tenant">Tenant</option>
            <option value="User">User</option>
            <option value="Payment">Payment</option>
            <option value="MessageOptOut">MessageOptOut</option>
          </select>
        </div>
        <div>
          <label class="form-label small text-muted">De</label>
          <input class="form-control form-control-sm" type="date" v-model="filters.from" />
        </div>
        <div>
          <label class="form-label small text-muted">Até</label>
          <input class="form-control form-control-sm" type="date" v-model="filters.to" />
        </div>
        <div>
          <button class="btn btn-sm btn-primary" @click="reload()">
            <i class="ti ti-search me-1"></i>Filtrar
          </button>
          <button class="btn btn-sm btn-ghost-secondary ms-1" @click="resetFilters">Limpar</button>
        </div>
      </div>
    </div>

    <!-- Aviso 403 -->
    <div v-if="forbidden" class="alert alert-danger">
      <i class="ti ti-lock me-1"></i>
      Apenas administradores podem acessar o histórico de auditoria.
    </div>

    <!-- Tabela -->
    <div v-else class="card" style="border-radius:14px">
      <div class="card-body p-0">
        <table class="table card-table table-vcenter mb-0">
          <thead>
            <tr>
              <th>Data</th>
              <th>Ação</th>
              <th>Recurso</th>
              <th>ID</th>
              <th>Usuário</th>
              <th>IP</th>
              <th>Detalhes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="7" class="text-center text-muted py-4">
                <span class="spinner-border spinner-border-sm me-2"></span>Carregando...
              </td>
            </tr>
            <tr v-else-if="!items.length">
              <td colspan="7" class="text-center text-muted py-4">
                <i class="ti ti-clipboard-list d-block mb-2" style="font-size:1.5rem"></i>
                Nenhum registro nos filtros selecionados.
              </td>
            </tr>
            <tr v-for="row in items" :key="row.id">
              <td class="small text-muted">{{ formatDate(row.created_at) }}</td>
              <td><code class="small">{{ row.action }}</code></td>
              <td class="small">{{ row.resource ?? '—' }}</td>
              <td class="small text-muted" style="font-family:'JetBrains Mono',monospace">{{ row.resource_id ?? '—' }}</td>
              <td class="small">{{ row.user_id ?? '—' }}</td>
              <td class="small text-muted" style="font-family:'JetBrains Mono',monospace">{{ row.ip_address ?? '—' }}</td>
              <td>
                <button v-if="row.metadata && Object.keys(row.metadata).length"
                        class="btn btn-sm btn-ghost-secondary" @click="expandedId = expandedId === row.id ? null : row.id">
                  <i :class="expandedId === row.id ? 'ti ti-chevron-up' : 'ti ti-chevron-down'"></i>
                </button>
                <pre v-if="expandedId === row.id" class="small mt-2"
                     style="background:rgba(0,0,0,0.05);padding:0.5rem;border-radius:6px;max-width:300px;white-space:pre-wrap;word-break:break-all">{{ JSON.stringify(row.metadata, null, 2) }}</pre>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pagination.last_page > 1" class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">
          Página {{ pagination.current_page }} de {{ pagination.last_page }} ({{ pagination.total }} registros)
        </span>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page <= 1"
                  @click="reload(pagination.current_page - 1)">
            <i class="ti ti-chevron-left"></i>
          </button>
          <button class="btn btn-sm btn-outline-secondary" :disabled="pagination.current_page >= pagination.last_page"
                  @click="reload(pagination.current_page + 1)">
            <i class="ti ti-chevron-right"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

interface AuditEntry {
  id: number
  action: string
  resource: string | null
  resource_id: number | null
  user_id: number | null
  ip_address: string | null
  user_agent: string | null
  metadata: Record<string, any> | null
  created_at: string
}

const { get } = useApi()
const toast = useToast()

const items = ref<AuditEntry[]>([])
const loading = ref(false)
const forbidden = ref(false)
const expandedId = ref<number | null>(null)
const pagination = reactive({ current_page: 1, last_page: 1, per_page: 30, total: 0 })
const filters = reactive({ action: '', resource: '', from: '', to: '' })

async function reload(page = 1) {
  loading.value = true
  forbidden.value = false
  try {
    const res = await get<any>('/audit-log', {
      page,
      action:   filters.action   || undefined,
      resource: filters.resource || undefined,
      from:     filters.from     || undefined,
      to:       filters.to       || undefined,
    })
    items.value = res?.data ?? []
    if (res?.meta) Object.assign(pagination, res.meta)
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 403) {
      forbidden.value = true
    } else {
      toast.error(e?.response?.data?.message ?? 'Erro ao carregar histórico')
    }
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.action = ''
  filters.resource = ''
  filters.from = ''
  filters.to = ''
  reload(1)
}

function formatDate(s: string): string {
  if (!s) return '—'
  try { return new Date(s).toLocaleString('pt-BR') } catch { return s }
}

onMounted(() => reload())
</script>
