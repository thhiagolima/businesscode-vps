<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">
          Tenants — Financeiro
        </h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">
          Saldo, limite e status de cobrança por tenant.
        </span>
      </div>
      <button class="btn btn-ghost-secondary" :disabled="loading" @click="load">
        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
        <i v-else class="ti ti-refresh me-1"></i>
        Atualizar
      </button>
    </div>

    <div class="card" style="border-radius:14px">
      <div class="card-body pb-0">
        <div class="row g-2 mb-3">
          <div class="col-md-4">
            <input
              class="form-control"
              v-model="filters.search"
              placeholder="Buscar por nome ou slug…"
              style="border-radius:10px"
              @input="applyFiltersDebounced"
            />
          </div>
          <div class="col-md-3">
            <select
              class="form-select"
              v-model="filters.status"
              style="border-radius:10px"
              @change="applyFilters"
            >
              <option value="">Todos os status</option>
              <option value="active">Active</option>
              <option value="grace">Grace (atrasado)</option>
              <option value="suspended">Suspended</option>
              <option value="blocked">Blocked</option>
            </select>
          </div>
        </div>
      </div>

      <div v-if="loading && !items.length" class="card-body text-center py-5">
        <span class="spinner-border spinner-border-sm"></span>
      </div>

      <div v-else-if="!items.length" class="card-body text-center py-5">
        <i class="ti ti-building-off" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">
          Nenhum tenant encontrado
        </h3>
      </div>

      <div v-else class="table-responsive">
        <table class="table card-table table-vcenter table-hover">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nome</th>
              <th>Status</th>
              <th class="text-end">Saldo</th>
              <th class="text-end">Limite</th>
              <th class="text-end">Uso</th>
              <th>Próx. cobrança</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="t in items"
              :key="t.id"
              :class="rowClass(t)"
              style="cursor:pointer"
              @click="goDetail(t.id)"
            >
              <td style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;color:var(--bc-text-muted)">
                #{{ t.id }}
              </td>
              <td class="fw-medium">{{ t.name }}</td>
              <td>
                <span :class="['badge', statusBadge(t.billing_status)]">
                  {{ statusLabel(t.billing_status) }}
                </span>
              </td>
              <td
                class="text-end"
                style="font-family:'JetBrains Mono',monospace"
                :class="{ 'text-danger fw-bold': (t.balance_cents ?? 0) < 0 }"
              >
                R$ {{ formatCents(t.balance_cents) }}
              </td>
              <td class="text-end" style="font-family:'JetBrains Mono',monospace">
                R$ {{ formatCents(t.credit_limit_cents) }}
              </td>
              <td class="text-end">
                <span :style="{ color: usageColor(t) }">{{ usagePct(t) }}%</span>
              </td>
              <td style="font-size:0.78rem">
                {{ formatDate(t.next_billing_at || t.last_billing_at) }}
                <div v-if="t.billing_cycle_day" style="font-size:0.7rem;color:var(--bc-text-muted)">
                  dia {{ t.billing_cycle_day }}
                </div>
              </td>
              <td class="text-end" @click.stop>
                <button class="btn btn-sm btn-ghost-primary" @click="goDetail(t.id)">
                  <i class="ti ti-eye me-1"></i>
                  Detalhes
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="meta.last_page > 1" class="card-footer d-flex align-items-center">
        <p class="m-0 text-muted" style="font-size:0.82rem">
          {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} de {{ meta.total }}
        </p>
        <ul class="pagination m-0 ms-auto">
          <li class="page-item" :class="{ disabled: meta.current_page <= 1 }">
            <a class="page-link" href="#" @click.prevent="goPage(meta.current_page - 1)">
              <i class="ti ti-chevron-left"></i>
            </a>
          </li>
          <li
            v-for="p in pageNumbers"
            :key="p"
            class="page-item"
            :class="{ active: p === meta.current_page }"
          >
            <a class="page-link" href="#" @click.prevent="goPage(p)">{{ p }}</a>
          </li>
          <li class="page-item" :class="{ disabled: meta.current_page >= meta.last_page }">
            <a class="page-link" href="#" @click.prevent="goPage(meta.current_page + 1)">
              <i class="ti ti-chevron-right"></i>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import type { BillingTenant } from '@/stores/billing'

type PaginationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

const { get } = useApi()
const toast = useToast()
const router = useRouter()

const items = ref<BillingTenant[]>([])
const meta = ref<PaginationMeta>({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
  from: null,
  to: null,
})
const loading = ref(false)
const filters = ref<{ search: string; status: string }>({ search: '', status: '' })

let searchTimer: any = null

function applyFiltersDebounced() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(applyFilters, 300)
}

function applyFilters() {
  meta.value.current_page = 1
  load()
}

function goPage(p: number) {
  if (p < 1 || p > meta.value.last_page) return
  meta.value.current_page = p
  load()
}

const pageNumbers = computed(() => {
  const pages: number[] = []
  const total = meta.value.last_page
  const current = meta.value.current_page
  const delta = 2
  for (let i = Math.max(1, current - delta); i <= Math.min(total, current + delta); i++) {
    pages.push(i)
  }
  return pages
})

async function load() {
  loading.value = true
  try {
    // First attempt the dedicated billing endpoint; fall back to /admin/tenants
    let resp: any
    try {
      resp = await get<any>('/admin/billing/tenants', {
        status: filters.value.status || undefined,
        search: filters.value.search || undefined,
        page: meta.value.current_page,
      })
    } catch (e: any) {
      if (e?.response?.status === 404) {
        resp = await get<any>('/admin/tenants', {
          search: filters.value.search || undefined,
          page: meta.value.current_page,
          with_billing: 1,
        })
      } else {
        throw e
      }
    }
    const list: any[] = Array.isArray(resp) ? resp : (resp?.data ?? [])
    const m = resp?.meta ?? null
    items.value = list
      .map(mapTenant)
      .filter(t => !filters.value.status || t.billing_status === filters.value.status)
    if (m) {
      meta.value = m
    } else {
      meta.value = {
        current_page: 1,
        last_page: 1,
        per_page: items.value.length || 20,
        total: items.value.length,
        from: items.value.length ? 1 : null,
        to: items.value.length || null,
      }
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao carregar tenants')
  } finally {
    loading.value = false
  }
}

function mapTenant(t: any): BillingTenant {
  // Adapt both /admin/tenants and /admin/billing/tenants response shapes
  const status: BillingTenant['billing_status'] =
    t.billing_status ?? legacyStatusToBilling(t.status)
  return {
    id: t.id,
    name: t.name,
    slug: t.slug,
    billing_status: status,
    balance_cents: Number(t.balance_cents ?? 0),
    credit_limit_cents: Number(t.credit_limit_cents ?? 0),
    billing_cycle_day: Number(t.billing_cycle_day ?? 0),
    last_billing_at: t.last_billing_at ?? null,
    next_billing_at: t.next_billing_at ?? null,
  }
}

function legacyStatusToBilling(s?: string): BillingTenant['billing_status'] {
  if (s === 'suspended') return 'suspended'
  return 'active'
}

function statusBadge(s: BillingTenant['billing_status']): string {
  return (
    {
      active: 'bg-success-lt text-success',
      grace: 'bg-warning-lt text-warning',
      suspended: 'bg-danger-lt text-danger',
      blocked: 'bg-dark text-white',
    } as Record<string, string>
  )[s] || 'bg-secondary-lt'
}

function statusLabel(s: BillingTenant['billing_status']): string {
  return (
    {
      active: 'Ativo',
      grace: 'Em atraso',
      suspended: 'Suspenso',
      blocked: 'Bloqueado',
    } as Record<string, string>
  )[s] || s
}

function rowClass(t: BillingTenant): string {
  if (t.billing_status === 'blocked' || t.billing_status === 'suspended') {
    return 'table-danger'
  }
  if (t.billing_status === 'grace') return 'table-warning'
  return ''
}

function usagePct(t: BillingTenant): string {
  const limit = Number(t.credit_limit_cents || 0)
  if (limit <= 0) return '—'
  const used = Math.max(0, -Number(t.balance_cents || 0))
  return ((used / limit) * 100).toFixed(0)
}

function usageColor(t: BillingTenant): string {
  const pct = parseFloat(usagePct(t))
  if (isNaN(pct)) return 'var(--bc-text-muted)'
  if (pct >= 100) return '#dc3545'
  if (pct >= 80) return '#fd7e14'
  if (pct >= 50) return '#0d6efd'
  return 'var(--bc-text-muted)'
}

function formatCents(c: number | null | undefined): string {
  return (Number(c || 0) / 100).toFixed(2).replace('.', ',')
}

function formatDate(iso?: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

function goDetail(id: number) {
  router.push(`/admin/billing/tenants/${id}`)
}

onMounted(load)
</script>

<style scoped>
.table-hover tbody tr:hover {
  background: var(--bc-gray-soft, rgba(255, 255, 255, 0.04));
}
</style>
