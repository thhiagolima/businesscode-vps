<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Relatório de Campanhas</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Performance e métricas</span>
      </div>
      <div class="d-flex gap-2">
      </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3" style="border-radius:14px">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12 col-md-3">
            <label class="form-label">De</label>
            <input type="date" class="form-control" v-model="filters.from" />
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Até</label>
            <input type="date" class="form-control" v-model="filters.to" />
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Canal</label>
            <select class="form-select" v-model="filters.type">
              <option value="">Todos</option>
              <option value="sms">SMS</option>
              <option value="voice">Voz</option>
              <option value="email">Email</option>
              <option value="whatsapp">WhatsApp</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" v-model="filters.status">
              <option value="">Todos</option>
              <option value="draft">Rascunho</option>
              <option value="scheduled">Agendada</option>
              <option value="processing">Processando</option>
              <option value="running">Em execução</option>
              <option value="completed">Concluída</option>
              <option value="canceled">Cancelada</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">Busca</label>
            <input type="text" class="form-control" placeholder="Nome" v-model="filters.search" />
          </div>
        </div>
        <div class="mt-3">
          <button class="btn btn-primary me-2" @click="applyFilters" :disabled="isLoading">
            <i class="ti ti-filter me-1"></i>Filtrar
          </button>
          <button class="btn btn-ghost-secondary" @click="resetFilters" :disabled="isLoading">
            Limpar
          </button>
        </div>
      </div>
    </div>

    <div class="card" style="border-radius:14px">
      <div class="table-responsive">
        <table class="table table-vcenter table-hover card-table">
          <thead>
            <tr>
              <th>Nome</th>
              <th>Canal</th>
              <th>Status</th>
              <th>Contatos</th>
              <th>Enviados</th>
              <th>Falhas</th>
              <th>Taxa</th>
              <th>Duração</th>
              <th class="w-1">Ações</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in list" :key="c.id">
              <td>{{ c.name }}</td>
              <td><StatusBadge :label="c.type.toUpperCase()" :status="c.type" /></td>
              <td><StatusBadge :label="statusLabel(c.status)" :status="c.status" /></td>
              <td>{{ c.estimated_contacts?.toLocaleString('pt-BR') }}</td>
              <td>{{ c.sent_count?.toLocaleString('pt-BR') }}</td>
              <td>{{ c.failed_count?.toLocaleString('pt-BR') }}</td>
              <td>
                <span :class="rateClass(c.delivery_rate)">{{ c.delivery_rate }}%</span>
              </td>
              <td>
                <span v-if="c.duration_minutes !== null">{{ c.duration_minutes }} min</span>
                <span v-else class="text-muted">—</span>
              </td>
              <td>
                <div class="btn-list">
                  <button class="btn btn-ghost-primary btn-sm" @click="goDetail(c.id)">
                    <i class="ti ti-eye"></i>
                  </button>
                  <button class="btn btn-ghost-secondary btn-sm" @click="exportCsv(c.id)">
                    <i class="ti ti-download"></i>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!list.length && !isLoading">
              <td colspan="9" class="p-0 border-0">
                <EmptyState
                  icon="ti-report-analytics"
                  title="Nenhum relatório"
                  description="Crie uma campanha para ver os relatórios."
                >
                  <router-link to="/campaigns/new" class="btn btn-primary">Nova campanha</router-link>
                </EmptyState>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex align-items-center">
        <div class="text-muted">
          Página {{ page }} de {{ lastPage }} — Total {{ total }}
        </div>
        <div class="ms-auto btn-list">
          <button class="btn btn-ghost-secondary btn-sm" :disabled="page <= 1 || isLoading" @click="prevPage">
            <i class="ti ti-chevron-left"></i>
          </button>
          <button class="btn btn-ghost-secondary btn-sm" :disabled="page >= lastPage || isLoading" @click="nextPage">
            <i class="ti ti-chevron-right"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useReportStore } from '@/stores/report'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const router = useRouter()
const store = useReportStore()
const isLoading = computed(() => store.isLoading)

const filters = reactive<{ from?: string; to?: string; type?: string; status?: string; search?: string; page?: number }>({
  from: undefined,
  to: undefined,
  type: '',
  status: '',
  search: '',
  page: 1,
})

const list = computed(() => store.campaigns?.items ?? [])
const meta = computed(() => store.campaigns?.meta ?? {})
const page = computed(() => meta.value?.pagination?.current_page ?? 1)
const lastPage = computed(() => meta.value?.pagination?.last_page ?? 1)
const total = computed(() => meta.value?.pagination?.total ?? 0)

const applyFilters = () => {
  filters.page = 1
  store.fetchCampaigns(filters)
}
const resetFilters = () => {
  filters.from = undefined
  filters.to = undefined
  filters.type = ''
  filters.status = ''
  filters.search = ''
  filters.page = 1
  store.fetchCampaigns({})
}
const prevPage = () => {
  if (page.value > 1) {
    filters.page = page.value - 1
    store.fetchCampaigns(filters)
  }
}
const nextPage = () => {
  if (page.value < lastPage.value) {
    filters.page = page.value + 1
    store.fetchCampaigns(filters)
  }
}
const goDetail = (id: number) => router.push(`/reports/campaigns/${id}`)
const exportCsv = (id: number) => store.exportCampaign(id)

store.fetchCampaigns({})

const statusLabel = (s: string) => {
  const map: Record<string,string> = {
    draft: 'Rascunho',
    scheduled: 'Agendada',
    processing: 'Processando',
    running: 'Em execução',
    completed: 'Concluída',
    canceled: 'Cancelada',
  }
  return map[s] ?? s
}
const statusColor = (s: string) => {
  const map: Record<string,string> = {
    draft: 'gray',
    scheduled: 'azure',
    processing: 'orange',
    running: 'blue',
    completed: 'green',
    canceled: 'red',
  }
  return map[s] ?? 'gray'
}
const rateClass = (rate: number) => {
  if (rate >= 90) return 'text-success'
  if (rate >= 70) return 'text-warning'
  return 'text-danger'
}
const channelBadge = (t: string) => {
  const map: Record<string,string> = {
    sms:      'bg-primary-lt text-primary',
    voice:    'bg-azure-lt text-azure',
    email:    'bg-success-lt text-success',
    whatsapp: 'bg-success-lt text-success',
  }
  return map[t] ?? 'bg-secondary-lt text-secondary'
}
</script>
