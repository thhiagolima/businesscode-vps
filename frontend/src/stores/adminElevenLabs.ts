import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

export interface ElevenLabsForm {
  model_id: string
  cost_per_char: number | string
  sale_per_char: number | string
  credits_per_char: number | string
  api_key?: string
}

export interface ElevenLabsSettings extends ElevenLabsForm {
  has_api_key?: boolean
  voices_count?: number
  last_sync_at?: string | null
}

export interface ElevenLabsVoice {
  voice_id: string
  name: string
  language?: string | null
  gender?: string | null
  category?: string | null
  preview_url?: string | null
  labels?: Record<string, string> | null
}

export const useElevenLabsStore = defineStore('adminElevenLabs', () => {
  const isLoading = ref(false)
  const toast = useToast()
  const { get, post, put } = useApi()

  const fetch = async (): Promise<ElevenLabsSettings> => get<ElevenLabsSettings>('/admin/settings/elevenlabs')

  const fetchVoices = async (): Promise<ElevenLabsVoice[]> => {
    const data = await get<ElevenLabsVoice[]>('/admin/elevenlabs/voices')
    return data ?? []
  }

  const save = async (form: ElevenLabsForm): Promise<void> => {
    isLoading.value = true
    try {
      await put('/admin/settings/elevenlabs', form)
      toast.success('Configurações salvas')
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  const test = async (apiKey: string | null): Promise<unknown> => {
    isLoading.value = true
    try {
      const res = await post('/admin/settings/elevenlabs/test', { api_key: apiKey ?? '__USE_SAVED__' })
      toast.success('Conexão OK')
      return res
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha no teste')
      throw e
    } finally {
      isLoading.value = false
    }
  }

  const syncVoices = async (): Promise<void> => {
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

  return { isLoading, fetch, fetchVoices, save, test, syncVoices }
})
