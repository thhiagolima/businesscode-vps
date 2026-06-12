import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

export interface DashboardStats {
  kpis: {
    total_campaigns: number
    active_campaigns: number
    sent_30d: number
    delivered_30d: number
    failed_30d: number
    delivery_rate_30d: number
    balance_cents: number
  }
  daily_sent: { date: string; label: string; total: number }[]
  by_channel: Record<string, number>
  recent_campaigns: CampaignReport[]
  recent_transactions: CreditTransaction[]
}

export interface CampaignReport {
  id: number
  name: string
  type: 'sms' | 'voice' | 'email' | 'whatsapp'
  status: string
  sent_count: number
  failed_count: number
  estimated_contacts: number
  created_at: string
  completed_at?: string | null
}

export interface CreditTransaction {
  id: number
  amount: number
  type: 'debit' | 'credit' | 'reserve' | 'release'
  reference_type: string
  reference_id: number
  description: string
  balance_after: number
  created_at: string
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface CreditSummary {
  total_debits: number
  total_credits: number
  balance: number
}

export const useReportStore = defineStore('report', () => {
  const isLoading = ref(false)
  const dashboardStats = ref<DashboardStats | null>(null)
  const campaigns = ref<{ items: CampaignReport[]; meta?: PaginationMeta } | null>(null)
  const currentCampaign = ref<CampaignReport | null>(null)
  const credits = ref<{ items: CreditTransaction[]; summary?: CreditSummary; meta?: PaginationMeta } | null>(null)

  const toast = useToast()
  const { get } = useApi()

  const fetchDashboard = async () => {
    isLoading.value = true
    try {
      const data = await get<DashboardStats>('/dashboard/stats')
      dashboardStats.value = data
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao carregar dashboard')
    } finally {
      isLoading.value = false
    }
  }

  const fetchCampaigns = async (filters?: Record<string, string | number | boolean | undefined>) => {
    isLoading.value = true
    try {
      const resp = await get<{ data: CampaignReport[]; meta: PaginationMeta }>('/reports/campaigns', filters)
      campaigns.value = { items: resp.data ?? [], meta: resp.meta }
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao carregar campanhas')
    } finally {
      isLoading.value = false
    }
  }

  const fetchCampaign = async (id: number | string) => {
    isLoading.value = true
    try {
      currentCampaign.value = await get<CampaignReport>(`/reports/campaigns/${id}`)
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao carregar relatório da campanha')
    } finally {
      isLoading.value = false
    }
  }

  async function exportCampaign(id: number) {
    const { get } = useApi()
    try {
      const response = await get<Blob>(`/reports/campaigns/${id}/export`, { responseType: 'blob' } as Record<string, unknown>)
      const url = window.URL.createObjectURL(response as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `campaign-${id}-report.csv`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      window.URL.revokeObjectURL(url)
    } catch (e: any) {
      toast.error('Erro ao exportar relatório')
    }
  }

  const fetchCredits = async (filters?: Record<string, string | number | boolean | undefined>) => {
    isLoading.value = true
    try {
      const resp = await get<{ data: CreditTransaction[]; summary: CreditSummary; meta: PaginationMeta }>('/reports/credits', filters)
      credits.value = { items: resp.data ?? [], summary: resp.summary, meta: resp.meta }
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao carregar extrato de créditos')
    } finally {
      isLoading.value = false
    }
  }

  return {
    isLoading,
    dashboardStats,
    campaigns,
    currentCampaign,
    credits,
    fetchDashboard,
    fetchCampaigns,
    fetchCampaign,
    exportCampaign,
    fetchCredits,
  }
})
