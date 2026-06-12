<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;margin:0">WhatsApp</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">Configure sua conexao com WhatsApp Business</span>
      </div>
    </div>

    <div v-if="loading" class="text-center py-5">
      <span class="spinner-border spinner-border-sm"></span>
    </div>

    <div v-else-if="channelStatus === 'disabled'" class="card" style="border-radius:14px">
      <div class="card-body text-center py-5">
        <i class="ti ti-lock" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">Canal WhatsApp nao habilitado</h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">Entre em contato com o administrador para habilitar o WhatsApp.</p>
      </div>
    </div>

    <div v-else-if="provider === 'infobip'" class="card" style="border-radius:14px">
      <div class="card-body text-center py-5">
        <i class="ti ti-brand-whatsapp" style="font-size:2.5rem;color:#25d366"></i>
        <h3 style="font-size:1rem;margin-top:1rem">WhatsApp via Infobip</h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">Seu WhatsApp e gerenciado pelo administrador via Infobip.<br>Entre em contato para configuracoes.</p>
        <button class="btn btn-outline-primary" style="border-radius:8px" @click="toast.info('Entre em contato com o administrador')">
          <i class="ti ti-message me-1"></i>Contatar administrador
        </button>
      </div>
    </div>

    <div v-else class="card" style="border-radius:14px">
      <div class="card-header">
        <h3 class="card-title">Configuracao Meta Cloud API</h3>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label required">Phone Number ID</label>
            <input v-model="form.phone_number_id" class="form-control" style="border-radius:10px" placeholder="Ex: 123456789012345" />
            <div class="form-hint">ID do numero na Meta Business Suite</div>
          </div>
          <div class="col-md-6">
            <label class="form-label required">WABA ID</label>
            <input v-model="form.waba_id" class="form-control" style="border-radius:10px" placeholder="Ex: 987654321098765" />
            <div class="form-hint">WhatsApp Business Account ID</div>
          </div>
          <div class="col-12">
            <label class="form-label required">Access Token</label>
            <input v-model="form.access_token" type="password" class="form-control" style="border-radius:10px" placeholder="Token permanente do System User" />
            <div class="form-hint">Gerado em Meta Business Suite &rarr; System Users</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Verify Token</label>
            <input v-model="form.verify_token" class="form-control" style="border-radius:10px" placeholder="Token para webhook" />
          </div>
          <div class="col-md-6">
            <label class="form-label">App Secret</label>
            <input v-model="form.app_secret" type="password" class="form-control" style="border-radius:10px" placeholder="Para validar webhooks" />
          </div>
        </div>
      </div>
      <div class="card-footer d-flex justify-content-between">
        <button class="btn btn-outline-primary" style="border-radius:8px" @click="testConnection" :disabled="testing">
          <span v-if="testing" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-plug me-1"></i>
          Testar conexao
        </button>
        <button class="btn btn-primary" style="border-radius:8px" @click="saveConfig" :disabled="saving">
          <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
          Salvar
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put, post } = useApi()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const testing = ref(false)
const channelStatus = ref('disabled')
const provider = ref('meta')
const form = ref({
  phone_number_id: '',
  waba_id: '',
  access_token: '',
  verify_token: '',
  app_secret: '',
})

async function loadConfig() {
  loading.value = true
  try {
    const res = await get<any>('/channels')
    const wa = res?.data?.whatsapp ?? res?.whatsapp ?? {}
    channelStatus.value = wa.status ?? 'disabled'
    provider.value = wa.config?.provider ?? 'meta'
    if (provider.value === 'meta' && wa.config) {
      form.value.phone_number_id = wa.config.phone_number_id ?? ''
      form.value.waba_id = wa.config.waba_id ?? ''
      if (wa.config.has_access_token) form.value.access_token = '********'
    }
  } catch { /* silent */ }
  finally { loading.value = false }
}

async function saveConfig() {
  saving.value = true
  try {
    const settings: Record<string, string> = {}
    if (form.value.phone_number_id) settings.phone_number_id = form.value.phone_number_id
    if (form.value.waba_id) settings.waba_id = form.value.waba_id
    if (form.value.access_token && form.value.access_token !== '********') settings.access_token = form.value.access_token
    if (form.value.verify_token) settings.verify_token = form.value.verify_token
    if (form.value.app_secret && form.value.app_secret !== '********') settings.app_secret = form.value.app_secret

    await put('/admin/settings/whatsapp', settings)
    toast.success('Configuracao salva')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
  } finally { saving.value = false }
}

async function testConnection() {
  testing.value = true
  try {
    const res = await post<any>('/admin/settings/whatsapp/test', {})
    if (res?.ok) {
      toast.success('Conexao OK!')
    } else {
      toast.error(res?.error ?? 'Falha na conexao')
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao testar')
  } finally { testing.value = false }
}

onMounted(loadConfig)
</script>
