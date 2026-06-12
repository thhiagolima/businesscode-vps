<template>
  <div class="modal fade" tabindex="-1" ref="modalEl">
    <div class="modal-dialog modal-dialog-centered modal-sm">
      <div class="modal-content" style="border-radius:16px;overflow:hidden">
        <div class="modal-header border-0 pb-0">
          <h3 class="modal-title" style="font-size:1rem;font-weight:700">Escolha o canal</h3>
          <button type="button" class="btn-close" @click="close"></button>
        </div>
        <div class="modal-body pt-2">
          <!-- Loading -->
          <div v-if="loading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm"></span>
          </div>

          <!-- Channel list -->
          <div v-else class="d-flex flex-column gap-2">
            <template v-for="ch in channelList" :key="ch.id">
              <!-- Enabled: click to go -->
              <button v-if="ch.status === 'enabled'" class="bc-channel-btn" @click="pick(ch.id)">
                <div class="bc-channel-icon" :style="{ background: ch.bg, color: ch.color }">
                  <i :class="`ti ${ch.icon}`" style="font-size:1.1rem"></i>
                </div>
                <div class="flex-fill text-start">
                  <div style="font-weight:600;font-size:0.9rem">{{ ch.label }}</div>
                  <div style="font-size:0.75rem;color:var(--bc-text-muted);line-height:1.3">{{ ch.desc }}</div>
                </div>
                <i class="ti ti-chevron-right" style="font-size:0.9rem;color:var(--bc-text-muted);opacity:0.5"></i>
              </button>

              <!-- Pending setup: show setup message -->
              <div v-else-if="ch.status === 'pending_setup'" class="bc-channel-btn bc-channel-pending">
                <div class="bc-channel-icon" :style="{ background: ch.bg, color: ch.color, opacity: 0.5 }">
                  <i :class="`ti ${ch.icon}`" style="font-size:1.1rem"></i>
                </div>
                <div class="flex-fill text-start">
                  <div style="font-weight:600;font-size:0.9rem;opacity:0.6">{{ ch.label }}</div>
                  <div style="font-size:0.72rem;color:var(--bc-text-muted);line-height:1.3">
                    <template v-if="ch.id === 'email'">
                      <i class="ti ti-alert-circle me-1" style="color:#d97706"></i>Configure o DNS do seu domínio
                    </template>
                    <template v-else-if="ch.id === 'whatsapp' && ch.config?.provider === 'infobip'">
                      <i class="ti ti-message me-1" style="color:#0064ff"></i>Entre em contato com o administrador
                    </template>
                    <template v-else-if="ch.id === 'whatsapp'">
                      <i class="ti ti-settings me-1" style="color:#d97706"></i>Configure sua conta WhatsApp Business
                    </template>
                    <template v-else>
                      Configuração pendente
                    </template>
                  </div>
                </div>
                <button v-if="ch.id === 'email'" class="btn btn-sm btn-outline-primary flex-shrink-0" style="font-size:0.72rem;padding:0.2rem 0.5rem;border-radius:6px" @click="$router.push('/profile'); close()">
                  Configurar
                </button>
                <button v-else-if="ch.id === 'whatsapp' && ch.config?.provider === 'infobip'" class="btn btn-sm btn-outline-primary flex-shrink-0" style="font-size:0.72rem;padding:0.2rem 0.5rem;border-radius:6px" @click="contactAdmin">
                  Contatar
                </button>
                <button v-else-if="ch.id === 'whatsapp'" class="btn btn-sm btn-outline-primary flex-shrink-0" style="font-size:0.72rem;padding:0.2rem 0.5rem;border-radius:6px" @click="$router.push('/admin/settings/whatsapp'); close()">
                  Configurar
                </button>
              </div>

              <!-- Disabled: show with upgrade prompt -->
              <div v-else-if="ch.status === 'disabled'" class="bc-channel-btn bc-channel-locked">
                <div class="bc-channel-icon" :style="{ background: ch.bg, color: ch.color, opacity: 0.3 }">
                  <i :class="`ti ${ch.icon}`" style="font-size:1.1rem"></i>
                </div>
                <div class="flex-fill text-start">
                  <div style="font-weight:600;font-size:0.9rem;opacity:0.4">{{ ch.label }}</div>
                  <div style="font-size:0.72rem;color:var(--bc-text-muted);line-height:1.3">
                    <i class="ti ti-lock me-1" style="font-size:0.65rem"></i>Disponível a partir do plano Starter
                  </div>
                </div>
                <button class="btn btn-sm btn-primary flex-shrink-0" style="font-size:0.72rem;padding:0.25rem 0.6rem;border-radius:6px" @click="$router.push('/profile'); close()">
                  Upgrade
                </button>
              </div>
            </template>

            <!-- No channels available -->
            <div v-if="!channelList.filter(c => c.status !== 'disabled').length" class="text-center py-4">
              <i class="ti ti-lock" style="font-size:2rem;color:var(--bc-text-muted);opacity:0.3"></i>
              <p style="font-size:0.85rem;color:var(--bc-text-muted);margin-top:0.5rem">Nenhum canal disponível.<br>Entre em contato com o administrador.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div v-if="backdrop" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch, computed } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

type Channel = 'sms' | 'voice' | 'email' | 'whatsapp'

interface ChannelDef {
  id: Channel
  label: string
  desc: string
  icon: string
  bg: string
  color: string
  status: string
  config: Record<string, any>
}

const channelDefs: Record<string, Omit<ChannelDef, 'status' | 'config'>> = {
  sms: { id: 'sms', label: 'SMS', desc: 'Mensagem de texto', icon: 'ti-message', bg: 'rgba(0,100,255,0.08)', color: '#0064ff' },
  whatsapp: { id: 'whatsapp', label: 'WhatsApp', desc: 'Templates aprovados', icon: 'ti-brand-whatsapp', bg: 'rgba(37,211,102,0.08)', color: '#25d366' },
  email: { id: 'email', label: 'Email', desc: 'HTML personalizado', icon: 'ti-mail', bg: 'rgba(59,130,246,0.08)', color: '#3b82f6' },
  voice: { id: 'voice', label: 'Voz', desc: 'Torpedo com áudio IA', icon: 'ti-phone', bg: 'rgba(234,179,8,0.08)', color: '#d97706' },
}

const emit = defineEmits<{ open: []; close: []; confirm: [channel: Channel] }>()
const props = defineProps<{ modelValue: boolean }>()

const { get } = useApi()
const toast = useToast()
const modalEl = ref<HTMLDivElement | null>(null)
const backdrop = ref(false)
const loading = ref(false)
const tenantChannels = ref<Record<string, { status: string; config: Record<string, any> }>>({})

const channelList = computed<ChannelDef[]>(() => {
  const order: Channel[] = ['sms', 'whatsapp', 'email', 'voice']
  return order.map(ch => ({
    ...channelDefs[ch],
    status: tenantChannels.value[ch]?.status ?? 'disabled',
    config: tenantChannels.value[ch]?.config ?? {},
  }))
})

async function fetchChannels() {
  loading.value = true
  try {
    const res = await get<any>('/channels')
    tenantChannels.value = res?.data ?? res ?? {}
  } catch {
    // Fallback: enable all (for superadmin or if endpoint doesn't exist)
    tenantChannels.value = {
      sms: { status: 'enabled', config: {} },
      voice: { status: 'enabled', config: {} },
      email: { status: 'enabled', config: {} },
      whatsapp: { status: 'enabled', config: {} },
    }
  } finally {
    loading.value = false
  }
}

function open() {
  if (!modalEl.value) return
  fetchChannels()
  modalEl.value.classList.add('show')
  modalEl.value.style.display = 'block'
  document.body.classList.add('modal-open')
  backdrop.value = true
  emit('open')
}

function close() {
  if (!modalEl.value) return
  modalEl.value.classList.remove('show')
  modalEl.value.style.display = 'none'
  document.body.classList.remove('modal-open')
  backdrop.value = false
  emit('close')
}

function pick(ch: Channel) {
  emit('confirm', ch)
  close()
}

function contactAdmin() {
  toast.info('Entre em contato com o administrador para configurar o WhatsApp via Infobip.')
  close()
}

watch(() => props.modelValue, (v) => { if (v) open(); else close() })
onMounted(() => { if (props.modelValue) open() })
onUnmounted(() => {
  if (backdrop.value) {
    document.body.classList.remove('modal-open')
    backdrop.value = false
  }
})
</script>

<style scoped>
.bc-channel-btn {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  border: 1px solid var(--bc-gray, #e4e8ef);
  border-radius: 12px;
  background: transparent;
  cursor: pointer;
  transition: all 0.15s ease;
  width: 100%;
  min-height: 56px;
  text-align: left;
}
.bc-channel-btn:hover {
  border-color: var(--bc-primary, #0064ff);
  background: rgba(0, 100, 255, 0.03);
}
.bc-channel-btn:active { transform: scale(0.98); }
.bc-channel-pending {
  cursor: default;
  opacity: 0.8;
}
.bc-channel-pending:hover {
  border-color: var(--bc-gray, #e4e8ef);
  background: transparent;
}
.bc-channel-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
[data-bs-theme="dark"] .bc-channel-btn { border-color: rgba(255,255,255,0.08); }
[data-bs-theme="dark"] .bc-channel-btn:hover { border-color: var(--bc-primary); background: rgba(0,100,255,0.06); }
[data-bs-theme="dark"] .bc-channel-pending:hover { border-color: rgba(255,255,255,0.08); background: transparent; }
.bc-channel-locked {
  cursor: default;
  opacity: 0.6;
  background: rgba(255,255,255,0.01);
  border-style: dashed;
}
.bc-channel-locked:hover {
  border-color: var(--bc-gray, #e4e8ef);
  background: rgba(255,255,255,0.02);
  transform: none;
}
[data-bs-theme="dark"] .bc-channel-locked { border-color: rgba(255,255,255,0.06); }
[data-bs-theme="dark"] .bc-channel-locked:hover { border-color: rgba(255,255,255,0.08); background: transparent; }
</style>
