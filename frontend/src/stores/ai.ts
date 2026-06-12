import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

export interface AiVariation {
  id: string
  text: string
  chars?: number
  tone?: string
}

export interface AiAnalysis {
  score?: number
  issues?: string[]
  suggestions?: string[]
  [key: string]: unknown
}

export interface AiImprovedVersion {
  id: string
  text: string
  chars: number
  change: string
}

export interface AiGenerateResponse {
  session_id: number
  generation_id: string
  variations: AiVariation[]
  credits_used: number
}

export interface AiAnalyzeResponse {
  generation_id: string
  analysis: AiAnalysis
  improved_versions: AiImprovedVersion[]
  credits_used: number
}

export interface AiSession {
  id: number
  campaign_id: number
  channel: 'sms' | 'voice' | 'email'
  status: 'pending' | 'completed' | 'failed'
  briefing?: Record<string, unknown> | null
  variations?: AiVariation[]
  created_at?: string
}

export interface AiContentModel {
  id: number
  name: string
  channel: 'sms' | 'voice' | 'email'
  content: string
  briefing?: Record<string, unknown> | null
  created_at?: string
}

export interface AudioGeneration {
  id: number
  audio_url?: string | null
  audio_path?: string | null
  duration_seconds?: number | null
  characters_used?: number | null
  credits_charged?: number
  status: 'pending' | 'processing' | 'completed' | 'failed'
  created_at?: string
}

export const useAiStore = defineStore('ai', () => {
  const { get, post, del } = useApi()
  const toast = useToast()

  const sessions = ref<AiSession[]>([])
  const models = ref<AiContentModel[]>([])
  const audioHistory = ref<AudioGeneration[]>([])

  const isGenerating = ref(false)
  const isAnalyzing = ref(false)
  const isGeneratingAudio = ref(false)

  async function generate(payload: {
    campaign_id: number
    channel: 'sms' | 'voice' | 'email'
    briefing: Record<string, unknown>
    variations: number
  }) {
    try {
      isGenerating.value = true
      const resp = await post<AiGenerateResponse>(
        '/ai/generate',
        payload
      )
      toast.success('Variações geradas com sucesso.')
      await fetchSessions(payload.campaign_id)
      return resp
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao gerar variações')
      throw e
    } finally {
      isGenerating.value = false
    }
  }

  async function analyze(payload: {
    campaign_id: number
    channel: 'sms' | 'voice' | 'email'
    content: string
  }) {
    try {
      isAnalyzing.value = true
      const resp = await post<AiAnalyzeResponse>(
        '/ai/analyze',
        payload
      )
      return resp
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao analisar conteúdo')
      throw e
    } finally {
      isAnalyzing.value = false
    }
  }

  async function fetchSessions(campaignId: number) {
    try {
      const resp = await get<AiSession[]>(`/campaigns/${campaignId}/ai-sessions`)
      sessions.value = resp ?? []
    } catch (e: any) {
      toast.error('Falha ao carregar sessões IA')
    }
  }

  async function fetchModels(channel?: 'sms' | 'voice' | 'email') {
    try {
      const resp = await get<AiContentModel[]>('/ai/models', channel ? { channel } : undefined)
      models.value = resp ?? []
    } catch (e: any) {
      toast.error('Falha ao carregar modelos')
    }
  }

  async function saveModel(data: { name: string; channel: 'sms' | 'voice' | 'email'; content: string; briefing?: Record<string, unknown> }) {
    try {
      const resp = await post<AiContentModel>('/ai/models', data)
      models.value.unshift(resp)
      toast.success('Modelo salvo!')
      return resp
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao salvar modelo')
      throw e
    }
  }

  async function deleteModel(id: number) {
    try {
      await del(`/ai/models/${id}`)
      models.value = models.value.filter(m => m.id !== id)
      toast.success('Modelo excluído.')
    } catch (e: any) {
      toast.error('Falha ao excluir modelo')
      throw e
    }
  }

  async function generateAudio(campaignId: number, data: { script: string; voice_id: string }) {
    try {
      isGeneratingAudio.value = true
      const resp = await post<AudioGeneration>(`/campaigns/${campaignId}/audio`, data)
      audioHistory.value.unshift(resp)
      toast.success('Áudio gerado com sucesso.')
      return resp
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Falha ao gerar áudio')
      throw e
    } finally {
      isGeneratingAudio.value = false
    }
  }

  async function fetchAudio(campaignId: number | null) {
    if (!campaignId) return
    try {
      const resp = await get<AudioGeneration[]>(`/campaigns/${campaignId}/audio`)
      audioHistory.value = resp ?? []
    } catch (e: any) {
      toast.error('Falha ao carregar áudios')
    }
  }

  async function refreshAudioUrl(id: number) {
    try {
      const resp = await get<{ audio_url: string }>(`/audio/${id}/refresh-url`)
      const idx = audioHistory.value.findIndex(a => a.id === id)
      if (idx >= 0) audioHistory.value[idx].audio_url = resp.audio_url
      toast.success('URL renovada.')
      return resp.audio_url
    } catch (e: any) {
      toast.error('Falha ao renovar URL')
      throw e
    }
  }

  return {
    sessions,
    models,
    audioHistory,
    isGenerating,
    isAnalyzing,
    isGeneratingAudio,
    generate,
    analyze,
    fetchSessions,
    fetchModels,
    saveModel,
    deleteModel,
    generateAudio,
    fetchAudio,
    refreshAudioUrl,
  }
})
