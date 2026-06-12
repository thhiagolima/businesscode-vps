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

export interface InfobipSettings extends InfobipForm {
  has_api_key?: boolean
}

export const useInfobipStore = defineStore('adminInfobip', () => {
  const isLoading = ref(false)
  const toast = useToast()
  const { get, post, put } = useApi()

  const fetch = async (): Promise<InfobipSettings> => get<InfobipSettings>('/admin/settings/infobip')

  const save = async (form: InfobipForm): Promise<void> => {
    isLoading.value = true
    try {
      await put('/admin/settings/infobip', form)
      toast.success('Configurações salvas')
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  const test = async (apiKey: string | null, baseUrl: string | null) => {
    isLoading.value = true
    try {
      const res = await post('/admin/settings/infobip/test', { api_key: apiKey ?? '__USE_SAVED__', base_url: baseUrl ?? null })
      toast.success('Conexão OK')
      return res
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha no teste')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  return { isLoading, fetch, save, test }
})
