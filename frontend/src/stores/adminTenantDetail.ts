import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

interface TenantSummary {
  id: number
  name: string
  slug: string
  status: string
  plan?: { id: number; name: string } | null
  balance_cents: number
  credit_limit_cents?: number
}

interface Kpis {
  campaigns_total: number
  campaigns_active: number
  contacts_total: number
  messages_sent_30d: number
  messages_delivered_30d: number
  conversations_open: number
  users_total: number
}

export const useAdminTenantDetailStore = defineStore('adminTenantDetail', () => {
  const { get } = useApi()

  const tenant = ref<TenantSummary | null>(null)
  const kpis = ref<Kpis | null>(null)
  const loading = ref(false)

  async function loadOverview(tenantId: number) {
    loading.value = true
    try {
      const resp = await get<any>(`/admin/tenants/${tenantId}/overview`)
      // backend wraps in { data: { tenant, kpis } }
      tenant.value = resp.data?.tenant ?? resp.tenant
      kpis.value = resp.data?.kpis ?? resp.kpis
    } finally {
      loading.value = false
    }
  }

  function reset() {
    tenant.value = null
    kpis.value = null
  }

  return { tenant, kpis, loading, loadOverview, reset }
})
