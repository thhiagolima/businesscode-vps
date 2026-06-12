<template>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Chatbot</h2>
      <span style="font-size:0.82rem;color:var(--bc-text-muted)">Persona e configurações do bot</span>
    </div>
    <div class="d-flex gap-2">
    </div>
  </div>

  <!-- Persona -->
  <div class="card mb-3" style="border-radius:14px">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="card-title">Persona do Bot</div>
      <span v-if="persona" :class="['badge', statusBadge.cls]">{{ statusBadge.label }}</span>
    </div>
    <div class="card-body">
      <div v-if="persona?.status === 'rejected'" class="alert alert-danger mb-3">
        <strong>Rejeitada:</strong> {{ persona.rejection_reason }}
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label required">Nome do bot</label>
          <input
            class="form-control"
            :class="{ 'is-invalid': formErrors.bot_name }"
            v-model="form.bot_name"
            placeholder="Ex: Ana"
            maxlength="50"
            required>
          <div v-if="formErrors.bot_name" class="invalid-feedback">{{ formErrors.bot_name }}</div>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label required">Tom</label>
          <select class="form-select" v-model="form.tone">
            <option value="formal">Formal e profissional</option>
            <option value="casual">Casual e descontraído</option>
            <option value="friendly">Amigável e acolhedor</option>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label required">Nome da empresa</label>
        <input
          class="form-control"
          :class="{ 'is-invalid': formErrors.company_name }"
          v-model="form.company_name"
          maxlength="100"
          required>
        <div v-if="formErrors.company_name" class="invalid-feedback">{{ formErrors.company_name }}</div>
      </div>
      <div class="mb-3">
        <label class="form-label required">Produtos / Serviços</label>
        <textarea
          class="form-control"
          :class="{ 'is-invalid': formErrors.products_services }"
          v-model="form.products_services"
          rows="3"
          maxlength="2000"
          placeholder="Descreva os produtos e serviços que o bot deve conhecer"
          required></textarea>
        <div v-if="formErrors.products_services" class="invalid-feedback">{{ formErrors.products_services }}</div>
        <span class="form-hint">{{ (form.products_services?.length ?? 0) }}/2000</span>
      </div>
      <div class="mb-3">
        <label class="form-label required">Regras de negócio</label>
        <textarea
          class="form-control"
          :class="{ 'is-invalid': formErrors.business_rules }"
          v-model="form.business_rules"
          rows="3"
          maxlength="2000"
          placeholder="Ex: Não dar descontos sem aprovação, encaminhar reclamações para gerência"
          required></textarea>
        <div v-if="formErrors.business_rules" class="invalid-feedback">{{ formErrors.business_rules }}</div>
        <span class="form-hint">{{ (form.business_rules?.length ?? 0) }}/2000</span>
      </div>
      <div class="mb-3">
        <label class="form-label">Instruções especiais</label>
        <textarea class="form-control" v-model="form.special_instructions" rows="2" maxlength="1000"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Horário de funcionamento</label>
        <input class="form-control" v-model="form.working_hours" placeholder="Ex: Seg-Sex 9h-18h, Sáb 9h-13h" maxlength="200">
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
      <span v-if="autoSaveStatus" class="text-muted small">
        <i class="ti ti-check me-1"></i>{{ autoSaveStatus }}
      </span>
      <span v-else></span>
      <div>
        <button class="btn btn-outline-primary me-2" @click="saveDraft" :disabled="isSaving">
          <i class="ti ti-device-floppy me-1"></i> Salvar rascunho
        </button>
        <button class="btn btn-primary" @click="savePersona" :disabled="isSaving">
          <span v-if="isSaving" class="spinner-border spinner-border-sm me-2"></span>
          Salvar e enviar para aprovação
        </button>
      </div>
    </div>
  </div>

  <!-- Configurações -->
  <div class="card" style="border-radius:14px">
    <div class="card-header"><div class="card-title">Configurações</div></div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-check form-switch">
          <input class="form-check-input" type="checkbox" v-model="chatSettings.enabled" @change="saveSettings" :disabled="!chatSettings.has_approved_persona">
          <span class="form-check-label">Chatbot ativado</span>
        </label>
        <div v-if="!chatSettings.has_approved_persona" class="form-hint text-warning">
          A persona precisa ser aprovada pelo administrador antes de ativar o chatbot.
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Modo de operação</label>
        <select class="form-select" v-model="chatSettings.mode" @change="saveSettings" :disabled="!chatSettings.enabled">
          <option value="suggestion">Sugestão — IA sugere, operador confirma</option>
          <option value="autonomous">Autônomo — IA responde automaticamente</option>
        </select>
        <div class="form-hint mt-1">
          <span v-if="chatSettings.mode === 'suggestion'">A IA gera sugestões de resposta que aparecem no inbox para o operador aprovar.</span>
          <span v-else>A IA responde automaticamente aos leads sem intervenção humana.</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put } = useApi()
const toast = useToast()
const saving = ref(false)
const isSaving = saving // alias used in template
const persona = ref<any>(null)
const autoSaveStatus = ref('')

const form = ref({
  bot_name: '', tone: 'friendly', company_name: '',
  products_services: '', business_rules: '',
  special_instructions: '', working_hours: '',
})

const formErrors = ref<Record<string, string>>({})

const chatSettings = ref({ mode: 'suggestion', enabled: false, has_approved_persona: false })

const statusBadge = computed(() => {
  const map: Record<string, { label: string; cls: string }> = {
    draft: { label: 'Rascunho', cls: 'bg-secondary' },
    pending_approval: { label: 'Aguardando aprovação', cls: 'bg-warning text-dark' },
    approved: { label: 'Aprovada', cls: 'bg-success' },
    rejected: { label: 'Rejeitada', cls: 'bg-danger' },
  }
  return map[persona.value?.status] ?? { label: '—', cls: 'bg-secondary' }
})

function validateForm(): boolean {
  const errors: Record<string, string> = {}
  if (!form.value.bot_name.trim()) {
    errors.bot_name = 'O nome do bot é obrigatório.'
  }
  if (!form.value.company_name.trim()) {
    errors.company_name = 'O nome da empresa é obrigatório.'
  }
  if (!form.value.products_services.trim()) {
    errors.products_services = 'Descreva os produtos e serviços do bot.'
  }
  if (!form.value.business_rules.trim()) {
    errors.business_rules = 'As regras de negócio são obrigatórias.'
  }
  formErrors.value = errors
  return Object.keys(errors).length === 0
}

async function loadPersona() {
  try {
    const data = await get<any>('/chatbot/persona')
    persona.value = data
    if (data) {
      form.value = {
        bot_name: data.bot_name ?? '',
        tone: data.tone ?? 'friendly',
        company_name: data.company_name ?? '',
        products_services: data.products_services ?? '',
        business_rules: data.business_rules ?? '',
        special_instructions: data.special_instructions ?? '',
        working_hours: data.working_hours ?? '',
      }
    }
  } catch { /* silent */ }
}

async function loadSettings() {
  try {
    const data = await get<any>('/chatbot/settings')
    chatSettings.value = { ...chatSettings.value, ...data }
  } catch { /* silent */ }
}

async function savePersona() {
  if (!validateForm()) return
  saving.value = true
  try {
    const res = await put<any>('/chatbot/persona', form.value)
    persona.value = res
    toast.success('Persona salva e enviada para aprovação')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao salvar') }
  finally { saving.value = false }
}

async function saveDraft() {
  saving.value = true
  try {
    const res = await put<any>('/chatbot/persona', { ...form.value, status: 'draft' })
    persona.value = res
    toast.success('Rascunho salvo')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao salvar rascunho') }
  finally { saving.value = false }
}

// Auto-save debounce (2 seconds after last change)
let autoSaveTimer: ReturnType<typeof setTimeout> | null = null
watch(form, () => {
  if (autoSaveTimer) clearTimeout(autoSaveTimer)
  autoSaveStatus.value = ''
  autoSaveTimer = setTimeout(async () => {
    if (saving.value) return
    try {
      const res = await put<any>('/chatbot/persona', { ...form.value, status: 'draft' })
      persona.value = res
      autoSaveStatus.value = 'Rascunho salvo automaticamente'
      setTimeout(() => { autoSaveStatus.value = '' }, 3000)
    } catch { /* silent auto-save failure */ }
  }, 2000)
}, { deep: true })

async function saveSettings() {
  try {
    await put('/chatbot/settings', { mode: chatSettings.value.mode, enabled: chatSettings.value.enabled })
    toast.success('Configurações atualizadas')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
    await loadSettings()
  }
}

onMounted(() => { loadPersona(); loadSettings() })
</script>
