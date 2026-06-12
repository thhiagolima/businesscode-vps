<template>
  <div>
    <div class="mb-3 d-flex align-items-center gap-2">
      <span class="badge bg-primary fs-6 px-3 py-2">
        <i :class="`ti ${channelIcon} me-2`"></i>{{ channelLabel }}
      </span>
      <span class="text-muted small" v-if="strategyLocked">
        <i class="ti ti-lock me-1"></i>Canal bloqueado após geração
      </span>
    </div>

    <!-- Toggle IA/Manual — NÃO mostrar para voice (VoiceStudio tem seu próprio toggle) -->
    <div v-if="channel !== 'voice'" class="mb-4">
      <div class="form-selectgroup">
        <label class="form-selectgroup-item">
          <input type="radio" class="form-selectgroup-input" value="ai" v-model="mode">
          <span class="form-selectgroup-label">
            <i class="ti ti-sparkles me-2 text-primary"></i>
            Gerar com IA
          </span>
        </label>
        <label class="form-selectgroup-item">
          <input type="radio" class="form-selectgroup-input" value="manual" v-model="mode">
          <span class="form-selectgroup-label">
            <i class="ti ti-pencil me-2"></i>
            Escrever manualmente
          </span>
        </label>
      </div>
    </div>

    <template v-if="channel === 'voice'">
      <VoiceStudio
        :campaign-id="campaignId as number"
        :content="content"
        @save="onSaveField" />
    </template>
    <template v-else>
      <AiMode v-if="mode === 'ai'"
        :channel="channel"
        :campaign-id="campaignId"
        @select="onVariationSelect" />

      <ManualMode v-if="mode === 'manual'"
        :channel="channel"
        :initial-content="content"
        :initial-subject="subject"
        :initial-audio-url="audioUrl"
        @select="onManualSave" />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import AiMode from './Step2Content/AiMode.vue'
import ManualMode from './Step2Content/ManualMode.vue'
import VoiceStudio from './VoiceStudio.vue'

interface Props {
  campaignId?: number | null
  channel: 'sms'|'voice'|'email'
  content?: string
  subject?: string
  audioUrl?: string
  strategyLocked?: boolean
}
const props = defineProps<Props>()
const emit = defineEmits<{
  save: [payload: { field: string; value: any }]
}>()

const mode = ref<'ai'|'manual'>('ai')

const channelIcon = computed(() => ({
  sms: 'ti-message',
  voice: 'ti-phone',
  email: 'ti-mail',
}[props.channel] ?? 'ti-speakerphone'))

const channelLabel = computed(() => ({
  sms: 'SMS',
  voice: 'Torpedo de Voz',
  email: 'Email',
}[props.channel] ?? props.channel))

const strategyLocked = computed(() => props.strategyLocked ?? false)

function onVariationSelect(v: { text: string }) {
  emit('save', { field: 'content', value: v.text })
  emit('save', { field: 'strategy_locked', value: true })
}

function onManualSave(v: { text?: string; subject?: string; audioUrl?: string }) {
  if (typeof v.text !== 'undefined') emit('save', { field: 'content', value: v.text })
  if (typeof v.subject !== 'undefined') emit('save', { field: 'subject', value: v.subject })
  if (typeof v.audioUrl !== 'undefined') emit('save', { field: 'audio_url', value: v.audioUrl })
}

function onSaveField(payload: { field: string; value: any }) {
  emit('save', payload)
}
</script>
