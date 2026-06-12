<template>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Settings - Infobip</h2>
      <span style="font-size:0.82rem;color:var(--bc-text-muted)">SMS, Voz e Email</span>
    </div>
    <div class="d-flex gap-2">
    </div>
  </div>
  <div class="card" style="border-radius:14px">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label required">Base URL</label>
        <input
          class="form-control"
          :class="{ 'is-invalid': formErrors.base_url }"
          v-model="form.base_url"
          placeholder="api.infobip.com"
          required />
        <div v-if="formErrors.base_url" class="invalid-feedback">{{ formErrors.base_url }}</div>
      </div>
      <div class="mb-3">
        <label class="form-label required">API Key</label>
        <input
          type="password"
          class="form-control"
          :class="{ 'is-invalid': formErrors.api_key }"
          v-model="form.api_key"
          placeholder="********"
          required />
        <div v-if="formErrors.api_key" class="invalid-feedback">{{ formErrors.api_key }}</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Sender SMS</label>
        <input
          class="form-control"
          :class="{ 'is-invalid': formErrors.sender_sms }"
          v-model="form.sender_sms" />
        <div v-if="formErrors.sender_sms" class="invalid-feedback">{{ formErrors.sender_sms }}</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Sender Voice</label>
        <input
          class="form-control"
          :class="{ 'is-invalid': formErrors.sender_voice }"
          v-model="form.sender_voice" />
        <div v-if="formErrors.sender_voice" class="invalid-feedback">{{ formErrors.sender_voice }}</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Sender Email</label>
        <input class="form-control" v-model="form.sender_email" />
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <button class="btn btn-ghost-secondary" @click="test" :disabled="isLoading">
        <span v-if="isLoading && testing" class="spinner-border spinner-border-sm me-2"></span>
        Testar conexão
      </button>
      <button class="btn btn-primary" @click="save" :disabled="isLoading && !testing">
        <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
        Salvar
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put } = useApi()
const toast = useToast()
const isLoading = ref(false)
const testing = ref(false)

type InfobipSettings = {
  api_key: string
  base_url: string
  sender_sms: string
  sender_voice: string
  sender_email: string
}

const form = ref<InfobipSettings>({
  api_key: '',
  base_url: '',
  sender_sms: '',
  sender_voice: '',
  sender_email: '',
})

const formErrors = ref<Record<string, string>>({})

function isValidUrl(value: string): boolean {
  if (!value) return false
  try {
    const url = value.startsWith('http') ? value : `https://${value}`
    new URL(url)
    return true
  } catch {
    return false
  }
}

function validateForm(): boolean {
  const errors: Record<string, string> = {}

  if (!form.value.base_url.trim()) {
    errors.base_url = 'A Base URL é obrigatória.'
  } else if (!isValidUrl(form.value.base_url.trim())) {
    errors.base_url = 'Informe uma URL válida (ex: api.infobip.com).'
  }

  // Require api_key only when the field is completely empty (not a masked placeholder)
  if (!form.value.api_key || form.value.api_key === '') {
    errors.api_key = 'A API Key é obrigatória.'
  }

  formErrors.value = errors
  return Object.keys(errors).length === 0
}

const load = async () => {
  isLoading.value = true
  try {
    const data = await get<Partial<InfobipSettings>>('/admin/settings/infobip')
    Object.assign(form.value, data)
  } finally {
    isLoading.value = false
  }
}

const save = async () => {
  if (!validateForm()) return
  isLoading.value = true
  try {
    const payload = { ...form.value }
    if (payload.api_key === '********' || payload.api_key === '') {
      delete (payload as any).api_key
    }
    await put('/admin/settings/infobip', payload)
    toast.success('Salvo!')
  } catch (e:any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
  } finally {
    isLoading.value = false
  }
}

const test = async () => {
  isLoading.value = true
  testing.value = true
  try {
    const api_key = form.value.api_key && form.value.api_key !== '********' ? form.value.api_key : '__USE_SAVED__'
    const { post } = useApi()
    await post('/admin/settings/infobip/test', { api_key, base_url: form.value.base_url })
    toast.success('Conexão OK')
  } catch (e:any) {
    toast.error(e?.response?.data?.message ?? 'Falha no teste')
  } finally {
    isLoading.value = false
    testing.value = false
  }
}

onMounted(load)
</script>
