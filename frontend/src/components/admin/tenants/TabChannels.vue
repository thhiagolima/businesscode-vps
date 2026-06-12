<template>
  <div class="card" style="border-radius:14px">
    <div class="card-header"><h3 class="card-title"><i class="ti ti-broadcast me-2"></i>Canais</h3></div>
    <div class="card-body">
      <div v-if="loading" class="text-center py-3">
        <span class="spinner-border spinner-border-sm"></span>
      </div>
      <div v-else class="d-flex flex-column gap-3">
        <div v-for="ch in channelsDefs" :key="ch.id" class="d-flex align-items-center gap-3 p-3" style="border:1px solid var(--bc-outline);border-radius:12px;background:var(--bc-gray-soft)">
          <div class="icon-container-md" :style="{ background: ch.bg, color: ch.color }">
            <i :class="`ti ${ch.icon}`" style="font-size:1rem"></i>
          </div>
          <div class="flex-fill">
            <div class="fw-bold" style="font-size:0.9rem">{{ ch.label }}</div>
            <div style="font-size:0.72rem;color:var(--bc-text-muted)">
              <template v-if="channelsData[ch.id]?.status === 'enabled'">
                <span style="color:#0d9668"><i class="ti ti-circle-check me-1"></i>Habilitado</span>
              </template>
              <template v-else-if="channelsData[ch.id]?.status === 'pending_setup'">
                <span style="color:#d97706"><i class="ti ti-clock me-1"></i>Pendente de configuração</span>
              </template>
              <template v-else>
                <span style="opacity:0.5"><i class="ti ti-circle-x me-1"></i>Desabilitado</span>
              </template>
            </div>
            <div v-if="ch.id === 'whatsapp' && channelsData.whatsapp?.status !== 'disabled'" class="mt-1">
              <select class="form-select form-select-sm" style="max-width:220px;font-size:0.75rem;border-radius:6px" v-model="whatsappProvider" @change="updateWhatsappProvider">
                <option value="meta">Meta (tenant configura)</option>
                <option value="infobip">Infobip (admin atribui)</option>
              </select>
            </div>
          </div>
          <select class="form-select form-select-sm" style="width:auto;font-size:0.78rem;border-radius:8px"
                  :value="channelsData[ch.id]?.status ?? 'disabled'"
                  @change="updateChannel(ch.id, ($event.target as HTMLSelectElement).value)">
            <option value="disabled">Desabilitado</option>
            <option value="enabled">Habilitado</option>
            <option value="pending_setup">Pendente</option>
          </select>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ tenantId: number }>()
const { get, put } = useApi()
const toast = useToast()

const loading = ref(false)
const channelsData = ref<Record<string, { status: string; config: Record<string, any> }>>({})
const whatsappProvider = ref('meta')

const channelsDefs = [
  { id: 'sms',      label: 'SMS',      icon: 'ti-message',         bg: 'rgba(0,100,255,0.08)',  color: '#0064ff' },
  { id: 'whatsapp', label: 'WhatsApp', icon: 'ti-brand-whatsapp',  bg: 'rgba(37,211,102,0.08)', color: '#25d366' },
  { id: 'email',    label: 'Email',    icon: 'ti-mail',            bg: 'rgba(59,130,246,0.08)', color: '#3b82f6' },
  { id: 'voice',    label: 'Voz',      icon: 'ti-phone',           bg: 'rgba(234,179,8,0.08)',  color: '#d97706' },
]

async function load() {
  loading.value = true
  channelsData.value = {}
  try {
    const res = await get<any>(`/admin/tenants/${props.tenantId}/channels`)
    const arr = Array.isArray(res) ? res : (res?.data ?? [])
    const map: Record<string, any> = {}
    for (const ch of arr) {
      map[ch.channel] = { status: ch.status, config: ch.config ?? {} }
    }
    channelsData.value = map
    whatsappProvider.value = map.whatsapp?.config?.provider ?? 'meta'
  } catch {
    toast.error('Erro ao carregar canais')
  } finally {
    loading.value = false
  }
}

async function updateChannel(channel: string, status: string) {
  try {
    const config = channelsData.value[channel]?.config ?? {}
    if (channel === 'whatsapp') {
      config.provider = whatsappProvider.value
    }
    await put(`/admin/tenants/${props.tenantId}/channels/${channel}`, { status, config })
    channelsData.value[channel] = { status, config }
    toast.success(`Canal ${channel} atualizado`)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

async function updateWhatsappProvider() {
  const current = channelsData.value.whatsapp
  if (!current || current.status === 'disabled') return
  const config = { ...(current.config ?? {}), provider: whatsappProvider.value }
  try {
    await put(`/admin/tenants/${props.tenantId}/channels/whatsapp`, { status: current.status, config })
    channelsData.value.whatsapp = { ...current, config }
    toast.success(`Provider WhatsApp: ${whatsappProvider.value}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

onMounted(load)
</script>
