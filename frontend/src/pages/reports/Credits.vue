<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Extrato de Créditos</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Histórico de transações</span>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" @click="exportCsv">
          <i class="ti ti-download me-1"></i> Exportar CSV
        </button>
      </div>
    </div>

    <!-- Resumo -->
    <div class="row row-deck row-cards">
      <div class="col-sm-6 col-lg">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div class="subheader">Saldo atual</div>
            <div class="h1 mt-2 mb-0">{{ summary.current_balance?.toLocaleString('pt-BR') ?? 0 }}</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div class="subheader">Debitado (30d)</div>
            <div class="h1 mt-2 mb-0 text-danger">{{ summary.total_debited_30d?.toLocaleString('pt-BR') ?? 0 }}</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div class="subheader">Creditado (30d)</div>
            <div class="h1 mt-2 mb-0 text-success">{{ summary.total_credited_30d?.toLocaleString('pt-BR') ?? 0 }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3" style="border-radius:14px">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-12 col-md-4">
            <label class="form-label">De</label>
            <input type="date" class="form-control" v-model="filters.from" />
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Até</label>
            <input type="date" class="form-control" v-model="filters.to" />
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Tipo</label>
            <select class="form-select" v-model="filters.type">
              <option value="">Todos</option>
              <option value="debit">Débito</option>
              <option value="credit">Crédito</option>
            </select>
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

    <!-- Tabela -->
    <div class="card" style="border-radius:14px">
      <div class="table-responsive">
        <table class="table table-vcenter table-hover card-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Descrição</th>
              <th>Valor</th>
              <th>Saldo após</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in items" :key="t.id">
              <td>
                <span class="badge" :class="t.type === 'credit' ? 'bg-success' : 'bg-danger'">
                  {{ t.type === 'credit' ? 'Crédito' : 'Débito' }}
                </span>
              </td>
              <td class="text-truncate">{{ t.description }}</td>
              <td>
                <span :class="t.type === 'credit' ? 'text-success' : 'text-danger'" class="fw-medium">
                  {{ t.type === 'credit' ? '+' : '-' }}{{ t.amount?.toLocaleString('pt-BR') }}
                </span>
              </td>
              <td>{{ t.balance_after?.toLocaleString('pt-BR') }}</td>
              <td>{{ formatDate(t.created_at) }}</td>
            </tr>
            <tr v-if="!items.length && !isLoading">
              <td colspan="5" class="p-0 border-0">
                <EmptyState
                  icon="ti-credit-card"
                  title="Nenhuma transação"
                  description="Quando créditos forem usados ou adicionados, o extrato aparecerá aqui."
                />
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
import { useReportStore } from '@/stores/report'
import EmptyState from '@/components/ui/EmptyState.vue'

const store = useReportStore()
const isLoading = computed(() => store.isLoading)
const filters = reactive<{ from?: string; to?: string; type?: string; page?: number }>({
  from: undefined,
  to: undefined,
  type: '',
  page: 1,
})

const items = computed(() => store.credits?.items ?? [])
const meta = computed(() => store.credits?.meta ?? {})
const summary = computed(() => store.credits?.summary ?? {})
const page = computed(() => meta.value?.pagination?.current_page ?? 1)
const lastPage = computed(() => meta.value?.pagination?.last_page ?? 1)
const total = computed(() => meta.value?.pagination?.total ?? 0)

const applyFilters = () => {
  filters.page = 1
  store.fetchCredits(filters)
}
const resetFilters = () => {
  filters.from = undefined
  filters.to = undefined
  filters.type = ''
  filters.page = 1
  store.fetchCredits({})
}
const prevPage = () => {
  if (page.value > 1) {
    filters.page = page.value - 1
    store.fetchCredits(filters)
  }
}
const nextPage = () => {
  if (page.value < lastPage.value) {
    filters.page = page.value + 1
    store.fetchCredits(filters)
  }
}

store.fetchCredits({})

function exportCsv() {
  if (!items.value.length) return
  const header = 'Tipo;Valor;Saldo;Referência;Descrição;Data\n'
  const rows = items.value.map((t: any) =>
    `${t.type};${t.amount};${t.balance_after};${t.reference_type ?? ''};${t.description ?? ''};${t.created_at ?? ''}`
  ).join('\n')
  const blob = new Blob(['\uFEFF' + header + rows], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'creditos.csv'
  a.click()
  URL.revokeObjectURL(url)
}

const formatDate = (date: string) => {
  try {
    return new Date(date).toLocaleString('pt-BR')
  } catch {
    return ''
  }
}
</script>
