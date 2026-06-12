<template>
  <div class="phone-preview">
    <div class="phone-screen">
      <div v-if="type === 'sms'" class="sms-bubble">
        <div class="sender">{{ senderDisplay }}</div>
        <div class="message">{{ content }}</div>
        <div class="sms-footer">
          <span class="time">Agora</span>
          <span class="char-count" :class="(content?.length ?? 0) > 160 ? 'over' : ''">{{ content?.length ?? 0 }}/160</span>
        </div>
      </div>
      <div v-else-if="type === 'voice'" class="voice-card text-center">
        <i class="ti ti-phone-call fs-1 text-primary"></i>
        <div class="mt-1">Torpedo de Voz</div>
        <audio v-if="audioUrl" controls :src="audioUrl" class="w-100 mt-2"></audio>
      </div>
      <div v-else-if="type === 'email'" class="email-preview">
        <div class="email-subject fw-bold">{{ subject }}</div>
        <div class="email-body" v-html="sanitizedContent"></div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import DOMPurify from 'dompurify'
import { computed } from 'vue'
import { useIdentity } from '@/composables/useIdentity'

interface Props {
  type: 'sms' | 'voice' | 'email'
  content?: string
  audioUrl?: string
  subject?: string
  /** Sender name to show in the SMS bubble preview.
   *  When omitted we fall back to the tenant brand name from `/public/identity`. */
  sender?: string
}
const props = defineProps<Props>()
const { identity } = useIdentity()

const senderDisplay = computed(() =>
  props.sender?.trim() || identity.value?.brand_name || '—'
)
const sanitizedContent = computed(() => DOMPurify.sanitize(props.content ?? ''))
</script>

<style scoped>
.phone-preview {
  background: #1a1a2e;
  border-radius: 2rem;
  padding: 1rem;
  border: 4px solid #333;
  max-width: 260px;
  margin: 0 auto;
  box-shadow: 0 20px 40px rgba(0,0,0,.3);
}
.phone-screen {
  background: #fff;
  border-radius: 1.2rem;
  min-height: 400px;
  padding: 1rem;
  overflow: hidden;
}
.sms-bubble {
  background: #e8f0ff;
  border-radius: 1rem;
  padding: .75rem 1rem;
  margin-top: 2rem;
}
.sms-bubble .sender {
  font-size: .7rem;
  color: #0064ff;
  font-weight: 600;
  margin-bottom: .25rem;
}
.sms-bubble .message {
  font-size: .85rem;
  color: #080c25;
  white-space: pre-wrap;
}
.sms-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: .4rem;
}
.sms-bubble .time {
  font-size: .65rem;
  color: #aaa;
}
.char-count {
  font-size: .6rem;
  font-family: 'JetBrains Mono', monospace;
  color: #0d9668;
  font-weight: 600;
}
.char-count.over {
  color: #dc2626;
}
</style>
