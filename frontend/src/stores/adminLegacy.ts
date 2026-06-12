import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

export interface InfobipForm {
  base_url: string
  sender_sms: string
  sender_voice: string
  sender_email: string
  api_key?: string
}

export interface AiForm {
  grok_model: string
  api_key?: string
}

export interface ElevenLabsForm {
  model_id: string
  cost_per_char: number | string
  sale_per_char: number | string
  credits_per_char: number | string
  api_key?: string
}

// Legacy combined store — prefer the focused stores (adminInfobip, adminAi, adminElevenLabs, adminTenants)
export const useAdminStore = defineStore('adminLegacy', () => {
  const isLoading = ref(false)
  const error = ref<string | null>(null)
  const toast = useToast()
  const { get, post, put } = useApi()

  // Infobip
  const fetchInfobip = async () => {
    return await get('/admin/settings/infobip')
  }
  const saveInfobip = async (form: InfobipForm) => {
    isLoading.value = true
    error.value = null
    try {
      await put('/admin/settings/infobip', form)
      toast.success('Configurações salvas')
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Erro ao salvar'
      toast.error(error.value)
      throw e
    } finally {
      isLoading.value = false
    }
  }
  const testInfobip = async (apiKey: string | null, baseUrl: string | null) => {
    isLoading.value = true
    error.value = null
    try {
      const res = await post('/admin/settings/infobip/test', { api_key: apiKey ?? '__USE_SAVED__', base_url: baseUrl ?? null })
      toast.success('Conexão OK')
      return res
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Falha no teste'
      toast.error(error.value)
      throw e
    } finally {
      isLoading.value = false
    }
  }

  // AI / Grok
  const fetchAi = async () => {
    return await get('/admin/settings/ai')
  }
  const saveAi = async (form: AiForm) => {
    isLoading.value = true
    error.value = null
    try {
      await put('/admin/settings/ai', form)
      toast.success('Configurações salvas')
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Erro ao salvar'
      toast.error(error.value)
      throw e
    } finally {
      isLoading.value = false
    }
  }
  const testAi = async (apiKey: string | null) => {
    isLoading.value = true
    error.value = null
    try {
      const res = await post('/admin/settings/ai/test', { api_key: apiKey ?? '__USE_SAVED__' })
      toast.success('Conexão OK')
      return res
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Falha no teste'
      toast.error(error.value)
      throw e
    } finally {
      isLoading.value = false
    }
  }

  // ElevenLabs
  const fetchElevenLabs = async () => {
    return await get('/admin/settings/elevenlabs')
  }
  const saveElevenLabs = async (form: ElevenLabsForm) => {
    isLoading.value = true
    error.value = null
    try {
      await put('/admin/settings/elevenlabs', form)
      toast.success('Configurações salvas')
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Erro ao salvar'
      toast.error(error.value)
      throw e
    } finally {
      isLoading.value = false
    }
  }
  const testElevenLabs = async (apiKey: string | null) => {
    isLoading.value = true
    error.value = null
    try {
      const res = await post('/admin/settings/elevenlabs/test', { api_key: apiKey ?? '__USE_SAVED__' })
      toast.success('Conexão OK')
      return res
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Falha no teste'
      toast.error(error.value)
      throw e
    } finally {
      isLoading.value = false
    }
  }
  const syncVoices = async () => {
    isLoading.value = true
    try {
      await post('/admin/elevenlabs/sync-voices', {})
      toast.success('Sincronização iniciada')
    } catch (e: any) {
      const msg = e?.response?.data?.message ?? 'Erro ao sincronizar'
      toast.error(msg)
      throw e
    } finally {
      isLoading.value = false
    }
  }
  const fetchVoices = async () => {
    return await get('/admin/elevenlabs/voices')
  }

  // Plans / Tenants
  const fetchTenants = async (params?: { page?: number; status?: string; search?: string }) => {
    const query = new URLSearchParams()
    if (params?.page) query.set('page', String(params.page))
    if (params?.status) query.set('status', params.status)
    if (params?.search) query.set('search', params.search)
    const url = '/admin/tenants' + (query.toString() ? `?${query.toString()}` : '')
    return await get(url)
  }
  const fetchPlans = async () => {
    return await get('/admin/plans')
  }

  return {
    isLoading,
    error,
    fetchInfobip,
    saveInfobip,
    testInfobip,
    fetchAi,
    saveAi,
    testAi,
    fetchElevenLabs,
    saveElevenLabs,
    testElevenLabs,
    syncVoices,
    fetchVoices,
    fetchTenants,
    fetchPlans,
  }
})

