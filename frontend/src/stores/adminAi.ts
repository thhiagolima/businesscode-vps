import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

export interface AiForm {
  grok_model: string
  api_key?: string
}

export interface AiSettings {
  grok_model: string
  has_api_key?: boolean
}

export interface AiTestResponse {
  ok: boolean
  models?: string[]
  error?: string
}

export const useAiSettingsStore = defineStore('adminAi', () => {
  const isLoading = ref(false)
  const toast = useToast()
  const { get, post, put } = useApi()

  const fetch = async (): Promise<AiSettings> => get<AiSettings>('/admin/settings/ai')

  const fetchModels = async (): Promise<string[]> => {
    const data = await get<string[] | unknown>('/admin/settings/ai/models')
    return Array.isArray(data) ? data : []
  }

  const save = async (form: AiForm): Promise<void> => {
    isLoading.value = true
    try {
      await put('/admin/settings/ai', form)
      toast.success('Configurações salvas')
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  const test = async (apiKey: string | null): Promise<AiTestResponse> => {
    isLoading.value = true
    try {
      const res = await post<AiTestResponse>('/admin/settings/ai/test', { api_key: apiKey ?? '__USE_SAVED__' })
      toast.success('Conexão OK')
      return res
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha no teste')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  return { isLoading, fetch, fetchModels, save, test }
})
