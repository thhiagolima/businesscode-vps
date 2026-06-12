import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

export interface Plan {
  id: number
  name: string
  slug: string
  price_monthly: number
  credits_included: number
  max_contacts: number
  max_campaigns: number
  overage_rate_sms: number
  overage_rate_voice: number
  overage_rate_email: number
  overage_rate_ai: number
  features: string[]
  status: 'active' | 'inactive'
}

export interface TenantChannelInfo {
  id: number
  channel: string
  status: string
  config?: Record<string, unknown>
}

export interface Tenant {
  id: number
  name: string
  slug: string
  status: 'active' | 'trial' | 'suspended'
  balance_cents: number
  plan_id: number | null
  plan?: Pick<Plan, 'id' | 'name'> | null
  enabled_channels?: TenantChannelInfo[]
  created_at?: string
}

export interface TenantPaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface TenantsListResponse {
  data: Tenant[]
  meta: TenantPaginationMeta
}

export interface TenantFilters {
  page?: number
  status?: string
  search?: string
}

export const useTenantsStore = defineStore('adminTenants', () => {
  const isLoading = ref(false)
  const toast = useToast()
  const { get } = useApi()

  const fetchTenants = async (params?: TenantFilters): Promise<TenantsListResponse> => {
    const query = new URLSearchParams()
    if (params?.page) query.set('page', String(params.page))
    if (params?.status) query.set('status', params.status)
    if (params?.search) query.set('search', params.search)
    const url = '/admin/tenants' + (query.toString() ? `?${query.toString()}` : '')
    isLoading.value = true
    try {
      return await get<TenantsListResponse>(url)
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao carregar tenants')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  const fetchPlans = async (): Promise<Plan[]> => {
    return await get<Plan[]>('/admin/plans')
  }

  return { isLoading, fetchTenants, fetchPlans }
})
