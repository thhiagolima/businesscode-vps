import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

export interface ServicePrice {
  service: string
  cost_cents: number
  sale_cents: number
  cost_micros?: number
  sale_micros?: number
  margin_cents: number
  margin_micros?: number
  margin_percent: number
  updated_at: string
  updated_by_name?: string
}

export interface BillingTenant {
  id: number
  name: string
  slug?: string
  billing_status: 'active' | 'grace' | 'suspended' | 'blocked'
  balance_cents: number
  credit_limit_cents: number
  billing_cycle_day: number
  last_billing_at?: string | null
  next_billing_at?: string | null
}

export interface BillingStats {
  total_balance_brl?: number
  total_owed_brl?: number
  mrr_30d_brl?: number
  margin_30d_brl?: number
  recovery_rate?: number
  top_consumers?: Array<{
    tenant_id: number
    tenant_name: string
    consumed_brl: number
  }>
  [key: string]: any
}

export interface TenantPriceOverride {
  service: string
  sale_cents: number | null
  default_sale_cents: number
  cost_cents: number
  has_override: boolean
}

export interface BalanceTransaction {
  id: number
  type: string
  amount_cents: number
  balance_after_cents: number
  description: string | null
  reason: string | null
  created_at: string
}

export const useBillingStore = defineStore('billing', () => {
  const prices = ref<ServicePrice[]>([])
  const tenants = ref<BillingTenant[]>([])
  const stats = ref<BillingStats | null>(null)

  const { get, post, put, patch } = useApi()

  async function loadPrices() {
    const r = await get<any>('/admin/billing/pricing')
    // Response may come wrapped {data: [...]} or as a bare array
    const list: ServicePrice[] = Array.isArray(r) ? r : (r?.data ?? [])
    prices.value = list
    return list
  }

  async function updatePrice(
    service: string,
    payload: {
      cost_cents: number
      sale_cents: number
      reason: string
      // Optional: pass micros to preserve sub-cent precision (1 micro = R$ 0,00001).
      // Backend prefers micros over cents when both are provided.
      cost_micros?: number
      sale_micros?: number
    }
  ) {
    await put(`/admin/billing/pricing/${service}`, payload)
    await loadPrices()
  }

  async function loadStats() {
    const r = await get<any>('/admin/billing/stats')
    stats.value = (r?.data ?? r) as BillingStats
    return stats.value
  }

  async function setCreditLimit(tenantId: number, cents: number, reason: string) {
    await patch(`/admin/billing/tenants/${tenantId}/credit-limit`, {
      credit_limit_cents: cents,
      reason,
    })
  }

  async function adjustBalance(tenantId: number, cents: number, reason: string) {
    await post(`/admin/billing/tenants/${tenantId}/adjust`, {
      amount_cents: cents,
      reason,
    })
  }

  async function setTenantPrice(
    tenantId: number,
    service: string,
    saleCents: number | null,
    reason: string
  ) {
    await post(`/admin/billing/tenants/${tenantId}/pricing`, {
      service,
      sale_cents: saleCents,
      reason,
    })
  }

  return {
    prices,
    tenants,
    stats,
    loadPrices,
    updatePrice,
    loadStats,
    setCreditLimit,
    adjustBalance,
    setTenantPrice,
  }
})
