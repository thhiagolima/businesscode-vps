<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <button class="btn btn-ghost-secondary btn-sm mb-2" @click="goBack">
          <i class="ti ti-chevron-left me-1"></i>
          Voltar
        </button>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">
          {{ tenant?.name || 'Tenant' }}
          <span
            v-if="tenant"
            :class="['badge', statusBadge(tenant.billing_status)]"
            style="font-size:0.7rem;vertical-align:middle"
          >
            {{ statusLabel(tenant.billing_status) }}
          </span>
        </h2>
        <span v-if="tenant" style="font-size:0.78rem;color:var(--bc-text-muted);font-family:'JetBrains Mono',monospace">
          #{{ tenant.id }} · {{ tenant.slug }}
        </span>
      </div>
      <button class="btn btn-ghost-secondary" :disabled="loading" @click="loadTenant">
        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
        <i v-else class="ti ti-refresh me-1"></i>
        Atualizar
      </button>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" role="tablist">
      <li class="nav-item">
        <a
          href="#"
          class="nav-link"
          :class="{ active: tab === 'balance' }"
          @click.prevent="tab = 'balance'"
        >
          <i class="ti ti-wallet me-1"></i>
          Saldo &amp; Limite
        </a>
      </li>
      <li class="nav-item">
        <a
          href="#"
          class="nav-link"
          :class="{ active: tab === 'pricing' }"
          @click.prevent="onTabPricing"
        >
          <i class="ti ti-cash me-1"></i>
          Preços customizados
        </a>
      </li>
      <li class="nav-item">
        <a
          href="#"
          class="nav-link"
          :class="{ active: tab === 'history' }"
          @click.prevent="onTabHistory"
        >
          <i class="ti ti-history me-1"></i>
          Histórico
        </a>
      </li>
    </ul>

    <!-- ABA 1: Saldo & Limite -->
    <div v-show="tab === 'balance'">
      <div v-if="!tenant && loading" class="card-body text-center py-5">
        <span class="spinner-border spinner-border-sm"></span>
      </div>
      <div v-else-if="tenant">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="card" style="border-radius:14px">
              <div class="card-body">
                <div style="font-size:0.78rem;color:var(--bc-text-muted)">Saldo atual</div>
                <div
                  style="font-size:1.75rem;font-weight:700;font-family:'JetBrains Mono',monospace"
                  :class="{ 'text-danger': (tenant.balance_cents ?? 0) < 0 }"
                >
                  R$ {{ formatCents(tenant.balance_cents) }}
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card" style="border-radius:14px">
              <div class="card-body">
                <div style="font-size:0.78rem;color:var(--bc-text-muted)">Limite de crédito</div>
                <div style="font-size:1.75rem;font-weight:700;font-family:'JetBrains Mono',monospace">
                  R$ {{ formatCents(tenant.credit_limit_cents) }}
                </div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card" style="border-radius:14px">
              <div class="card-body">
                <div style="font-size:0.78rem;color:var(--bc-text-muted)">Uso da linha</div>
                <div
                  style="font-size:1.75rem;font-weight:700"
                  :style="{ color: usageColor() }"
                >
                  {{ usagePct() }}%
                </div>
                <div style="font-size:0.7rem;color:var(--bc-text-muted)">
                  Próx. cobrança:
                  {{ formatDate(tenant.next_billing_at || tenant.last_billing_at) }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card" style="border-radius:14px">
          <div class="card-header">
            <h3 class="card-title">Ações administrativas</h3>
          </div>
          <div class="card-body d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" @click="openCreditLimit">
              <i class="ti ti-adjustments me-1"></i>
              Ajustar limite
            </button>
            <button class="btn btn-success" @click="openAdjust">
              <i class="ti ti-cash-plus me-1"></i>
              Ajuste manual
            </button>
            <button
              v-if="tenant.billing_status !== 'active'"
              class="btn btn-warning"
              :disabled="unblocking"
              @click="unblock"
            >
              <span v-if="unblocking" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="ti ti-lock-open me-1"></i>
              Desbloquear (forçar active)
            </button>
            <button class="btn btn-ghost-secondary" disabled title="Endpoint não implementado nesta fase">
              <i class="ti ti-bolt me-1"></i>
              Forçar cobrança (WIP)
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ABA 2: Preços customizados -->
    <div v-show="tab === 'pricing'">
      <div class="alert alert-info mb-3" role="alert">
        <i class="ti ti-info-circle me-1"></i>
        Overrides individuais sobrescrevem o preço global para este tenant. Custo permanece global.
      </div>

      <div class="card" style="border-radius:14px">
        <div v-if="pricingLoading" class="card-body text-center py-5">
          <span class="spinner-border spinner-border-sm"></span>
        </div>
        <div v-else class="table-responsive">
          <table class="table card-table table-vcenter">
            <thead>
              <tr>
                <th>Serviço</th>
                <th class="text-end">Custo (global)</th>
                <th class="text-end">Venda padrão</th>
                <th class="text-end">Override</th>
                <th>Status</th>
                <th class="text-end">Ações</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in pricingRows" :key="p.service">
                <td style="font-weight:600">{{ serviceLabel(p.service) }}</td>
                <td class="text-end" style="font-family:'JetBrains Mono',monospace">
                  R$ {{ formatCents(p.cost_cents) }}
                </td>
                <td class="text-end" style="font-family:'JetBrains Mono',monospace;color:var(--bc-text-muted)">
                  R$ {{ formatCents(p.default_sale_cents) }}
                </td>
                <td class="text-end fw-bold" style="font-family:'JetBrains Mono',monospace">
                  <span v-if="p.has_override">R$ {{ formatCents(p.sale_cents ?? 0) }}</span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td>
                  <span v-if="p.has_override" class="badge bg-blue-lt text-primary">Custom</span>
                  <span v-else class="badge bg-secondary-lt">Padrão</span>
                </td>
                <td class="text-end">
                  <button class="btn btn-sm btn-primary" @click="editPrice(p)">
                    <i class="ti ti-pencil me-1"></i>
                    {{ p.has_override ? 'Editar' : 'Criar override' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ABA 3: Histórico -->
    <div v-show="tab === 'history'">
      <div class="card" style="border-radius:14px">
        <div class="card-header">
          <h3 class="card-title">Histórico de transações de saldo</h3>
        </div>
        <div v-if="historyLoading" class="card-body text-center py-5">
          <span class="spinner-border spinner-border-sm"></span>
        </div>
        <div v-else-if="!historyAvailable" class="card-body text-center py-5">
          <i class="ti ti-clock-off" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
          <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">Em construção</h3>
          <p style="font-size:0.85rem;color:var(--bc-text-muted)">
            Endpoint de transações de saldo será adicionado na próxima fase.
          </p>
        </div>
        <div v-else-if="!transactions.length" class="card-body text-center py-5">
          <i class="ti ti-list" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
          <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">
            Nenhuma transação registrada
          </h3>
        </div>
        <div v-else class="table-responsive">
          <table class="table card-table table-vcenter">
            <thead>
              <tr>
                <th>Data</th>
                <th>Tipo</th>
                <th class="text-end">Valor</th>
                <th class="text-end">Saldo após</th>
                <th>Descrição / Razão</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="tx in transactions" :key="tx.id">
                <td style="font-size:0.78rem">{{ formatDateTime(tx.created_at) }}</td>
                <td>
                  <span class="badge bg-secondary-lt">{{ tx.type }}</span>
                </td>
                <td
                  class="text-end"
                  style="font-family:'JetBrains Mono',monospace"
                  :class="{ 'text-success': tx.amount_cents > 0, 'text-danger': tx.amount_cents < 0 }"
                >
                  {{ tx.amount_cents > 0 ? '+' : '' }}R$ {{ formatCents(tx.amount_cents) }}
                </td>
                <td class="text-end" style="font-family:'JetBrains Mono',monospace">
                  R$ {{ formatCents(tx.balance_after_cents) }}
                </td>
                <td style="font-size:0.82rem">
                  <div v-if="tx.description">{{ tx.description }}</div>
                  <div v-if="tx.reason" style="color:var(--bc-text-muted);font-size:0.75rem">
                    {{ tx.reason }}
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modals -->
    <CreditLimitModal
      v-if="showCreditLimit && tenant"
      :tenant-id="tenant.id"
      :current-cents="tenant.credit_limit_cents"
      @close="showCreditLimit = false"
      @saved="onLimitSaved"
    />

    <ManualAdjustModal
      v-if="showAdjust && tenant"
      :tenant-id="tenant.id"
      @close="showAdjust = false"
      @saved="onAdjustSaved"
    />

    <PriceEditModal
      v-if="priceEditing && tenant"
      :price="priceEditingShape"
      mode="tenant"
      :tenant-id="tenant.id"
      @close="priceEditing = null"
      @saved="onPriceSaved"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import {
  useBillingStore,
  type BillingTenant,
  type BalanceTransaction,
  type TenantPriceOverride,
} from '@/stores/billing'
import CreditLimitModal from '@/components/billing/CreditLimitModal.vue'
import ManualAdjustModal from '@/components/billing/ManualAdjustModal.vue'
import PriceEditModal from '@/components/billing/PriceEditModal.vue'

const route = useRoute()
const router = useRouter()
const { get, post } = useApi()
const toast = useToast()
const store = useBillingStore()

const tenantId = computed(() => Number(route.params.id))

const tenant = ref<BillingTenant | null>(null)
const loading = ref(false)

const tab = ref<'balance' | 'pricing' | 'history'>('balance')

const showCreditLimit = ref(false)
const showAdjust = ref(false)
const unblocking = ref(false)

const pricingRows = ref<TenantPriceOverride[]>([])
const pricingLoading = ref(false)
const priceEditing = ref<TenantPriceOverride | null>(null)
const priceEditingShape = computed(() => {
  const p = priceEditing.value
  if (!p) return { service: '', cost_cents: 0, sale_cents: 0, has_override: false }
  return {
    service: p.service,
    cost_cents: p.cost_cents,
    sale_cents: p.has_override ? (p.sale_cents ?? p.default_sale_cents) : p.default_sale_cents,
    has_override: p.has_override,
  }
})

const transactions = ref<BalanceTransaction[]>([])
const historyLoading = ref(false)
const historyAvailable = ref(true)

function goBack() {
  router.push('/admin/billing/tenants')
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

function formatCents(c: number | null | undefined): string {
  return (Number(c || 0) / 100).toFixed(2).replace('.', ',')
}

function formatDate(iso?: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatDateTime(iso?: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function serviceLabel(s: string): string {
  const map: Record<string, string> = {
    sms: 'SMS',
    voice: 'Voz (TTS)',
    email: 'Email',
    ai_generation: 'Geração IA',
    audio_tts: 'Áudio TTS',
  }
  return map[s] || s
}

function usagePct(): string {
  if (!tenant.value) return '0'
  const limit = Number(tenant.value.credit_limit_cents || 0)
  if (limit <= 0) return '—'
  const used = Math.max(0, -Number(tenant.value.balance_cents || 0))
  return ((used / limit) * 100).toFixed(0)
}

function usageColor(): string {
  const pct = parseFloat(usagePct())
  if (isNaN(pct)) return 'var(--bc-text-muted)'
  if (pct >= 100) return '#dc3545'
  if (pct >= 80) return '#fd7e14'
  if (pct >= 50) return '#0d6efd'
  return 'var(--bc-text-muted)'
}

async function loadTenant() {
  loading.value = true
  try {
    let resp: any
    try {
      resp = await get<any>(`/admin/billing/tenants/${tenantId.value}`)
    } catch (e: any) {
      if (e?.response?.status === 404) {
        resp = await get<any>(`/admin/tenants/${tenantId.value}`)
      } else {
        throw e
      }
    }
    const raw = resp?.data ?? resp
    tenant.value = {
      id: raw.id,
      name: raw.name,
      slug: raw.slug,
      billing_status: raw.billing_status ?? (raw.status === 'suspended' ? 'suspended' : 'active'),
      balance_cents: Number(raw.balance_cents ?? 0),
      credit_limit_cents: Number(raw.credit_limit_cents ?? 0),
      billing_cycle_day: Number(raw.billing_cycle_day ?? 0),
      last_billing_at: raw.last_billing_at ?? null,
      next_billing_at: raw.next_billing_at ?? null,
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao carregar tenant')
  } finally {
    loading.value = false
  }
}

async function loadPricing() {
  if (!tenantId.value) return
  pricingLoading.value = true
  try {
    // Ensure global prices are loaded for fallback display
    if (!store.prices.length) {
      try {
        await store.loadPrices()
      } catch {}
    }
    let resp: any = null
    try {
      resp = await get<any>(`/admin/billing/tenants/${tenantId.value}/pricing`)
    } catch (e: any) {
      if (e?.response?.status !== 404) throw e
    }
    const list: any[] = Array.isArray(resp) ? resp : (resp?.data ?? [])

    // Build merged rows: 1 row per global service, marking override when present
    const overridesByService: Record<string, any> = {}
    for (const o of list) {
      if (o?.service) overridesByService[o.service] = o
    }

    pricingRows.value = store.prices.map(p => {
      const ov = overridesByService[p.service]
      return {
        service: p.service,
        cost_cents: p.cost_cents,
        default_sale_cents: p.sale_cents,
        sale_cents: ov ? Number(ov.sale_cents ?? 0) : null,
        has_override: !!ov,
      } as TenantPriceOverride
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao carregar preços do tenant')
  } finally {
    pricingLoading.value = false
  }
}

async function loadHistory() {
  if (!tenantId.value) return
  historyLoading.value = true
  historyAvailable.value = true
  try {
    let resp: any = null
    try {
      resp = await get<any>(`/admin/billing/tenants/${tenantId.value}/transactions`)
    } catch (e: any) {
      if (e?.response?.status === 404) {
        historyAvailable.value = false
        transactions.value = []
        return
      }
      throw e
    }
    const list: any[] = Array.isArray(resp) ? resp : (resp?.data ?? [])
    transactions.value = list.map(tx => ({
      id: tx.id,
      type: tx.type ?? 'unknown',
      amount_cents: Number(tx.amount_cents ?? 0),
      balance_after_cents: Number(tx.balance_after_cents ?? 0),
      description: tx.description ?? null,
      reason: tx.reason ?? null,
      created_at: tx.created_at,
    }))
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao carregar histórico')
  } finally {
    historyLoading.value = false
  }
}

function onTabPricing() {
  tab.value = 'pricing'
  if (!pricingRows.value.length) loadPricing()
}

function onTabHistory() {
  tab.value = 'history'
  if (!transactions.value.length && historyAvailable.value) loadHistory()
}

function openCreditLimit() {
  showCreditLimit.value = true
}

function openAdjust() {
  showAdjust.value = true
}

function onLimitSaved() {
  showCreditLimit.value = false
  loadTenant()
}

function onAdjustSaved() {
  showAdjust.value = false
  loadTenant()
  if (tab.value === 'history') loadHistory()
}

function editPrice(p: TenantPriceOverride) {
  priceEditing.value = p
}

function onPriceSaved() {
  priceEditing.value = null
  loadPricing()
}

async function unblock() {
  if (!tenant.value) return
  unblocking.value = true
  try {
    // Use manual adjust with R$ 0 + reason to register the audit;
    // unblocking semantics are domain-level via balance.
    // Backend may also expose a dedicated endpoint; try it first.
    try {
      await post(`/admin/billing/tenants/${tenant.value.id}/unblock`, {
        reason: 'Desbloqueio manual via admin UI',
      })
    } catch (e: any) {
      if (e?.response?.status !== 404) throw e
      toast.error('Endpoint de desbloqueio não disponível no backend')
      return
    }
    toast.success('Tenant desbloqueado')
    await loadTenant()
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao desbloquear')
  } finally {
    unblocking.value = false
  }
}

onMounted(loadTenant)
</script>
