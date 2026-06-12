<template>
  <div class="card">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label required">
          Mensagem
          <span class="form-help ms-1" data-bs-toggle="tooltip" title="Escreva diretamente a mensagem que será enviada aos contatos">?</span>
        </label>
        <textarea class="form-control" :rows="channel === 'email' ? 8 : 5" v-model="manualContent" :placeholder="placeholder"></textarea>

        <div v-if="channel === 'sms'" class="d-flex justify-content-between mt-1">
          <small class="form-hint">
            <span v-if="charCount > 160" class="text-warning">
              <i class="ti ti-alert-triangle me-1"></i>
              Mensagem dividida em {{ Math.ceil(charCount / 160) }} SMSs
            </span>
          </small>
          <small :class="charCount > 160 ? 'text-warning' : 'text-muted'">
            {{ charCount }} caracteres
          </small>
        </div>
      </div>

      <div class="mb-3" v-if="channel === 'email'">
        <label class="form-label required">Assunto do email</label>
        <input type="text" class="form-control" v-model="subject" placeholder="Ex: Oferta exclusiva para você!" />
      </div>

      <div class="mb-3" v-if="channel === 'voice'">
        <label class="form-label">URL do arquivo de áudio</label>
        <input type="url" class="form-control" v-model="audioUrl" placeholder="https://..." />
        <div class="form-hint">
          Cole a URL de um arquivo .mp3 ou .wav já hospedado.
        </div>
        <audio v-if="audioUrl" controls :src="audioUrl" class="w-100 mt-2"></audio>
      </div>

      <div class="d-flex gap-2 flex-wrap mt-3">
        <button class="btn btn-outline-primary" :disabled="!manualContent || isAnalyzing" @click="openAnalysis">
          <span v-if="isAnalyzing" class="spinner-border spinner-border-sm me-2"></span>
          <i v-else class="ti ti-brain me-2"></i>
          {{ isAnalyzing ? 'Analisando...' : 'Analisar com IA' }}
        </button>

        <button class="btn btn-outline-secondary" :disabled="!manualContent" @click="saveModel">
          <i class="ti ti-bookmark me-2"></i>
          Salvar como modelo
        </button>
      </div>

      <div v-if="analysisResult" class="card mt-3">
        <div class="card-header py-2">
          <div class="card-title"><i class="ti ti-sparkles me-1"></i> Análise IA</div>
          <button class="btn btn-sm btn-ghost-secondary ms-auto" @click="analysisResult = null">
            <i class="ti ti-x"></i>
          </button>
        </div>
        <div class="card-body" style="white-space:pre-wrap;font-size:0.85rem">{{ analysisResult }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useToast } from '@/composables/useToast'
import { useApi } from '@/composables/useApi'

interface Props {
  channel: 'sms'|'voice'|'email'
  initialContent?: string
  initialSubject?: string
  initialAudioUrl?: string
}
const props = defineProps<Props>()
const emit = defineEmits<{ select: [{ text?: string; subject?: string; audioUrl?: string }] }>()
const toast = useToast()
const { post } = useApi()

const manualContent = ref<string>(props.initialContent ?? '')
const subject = ref<string>(props.initialSubject ?? '')
const audioUrl = ref<string>(props.initialAudioUrl ?? '')
const isAnalyzing = ref<boolean>(false)
const analysisResult = ref<string | null>(null)

const charCount = computed(() => manualContent.value.length)
const placeholder = computed(() => {
  if (props.channel === 'sms') return 'Escreva uma mensagem curta de até 160 caracteres...'
  if (props.channel === 'voice') return 'Descreva o roteiro de áudio ou cole a URL acima...'
  return 'Escreva o conteúdo do email (HTML simples permitido)...'
})

let debounceTimer: any = null
watch(manualContent, (val) => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    emit('select', { text: val })
  }, 500)
})
watch(subject, () => emit('select', { subject: subject.value }))
watch(audioUrl, () => emit('select', { audioUrl: audioUrl.value }))

async function openAnalysis() {
  try {
    isAnalyzing.value = true
    const res = await post<any>('/ai/analyze', {
      campaign_id: null,
      channel: props.channel,
      content: manualContent.value,
    })
    const result = res?.analysis ?? res?.result ?? res?.message ?? null
    if (result) {
      analysisResult.value = result
    } else {
      toast.info('Análise solicitada — confira sugestões de melhoria')
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Falha ao analisar com IA')
  } finally {
    isAnalyzing.value = false
  }
}

async function saveModel() {
  if (!manualContent.value) return
  try {
    await post('/ai/models', {
      name: `${props.channel.toUpperCase()} - ${new Date().toLocaleDateString('pt-BR')}`,
      channel: props.channel,
      content: manualContent.value,
      briefing: {},
    })
    toast.success('Modelo salvo')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar modelo')
  }
}
</script>
