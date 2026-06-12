<template>
  <!-- Success screen -->
  <div v-if="sendSuccess" class="text-center py-5">
    <div class="mb-4">
      <div class="avatar avatar-xl rounded-circle bg-success-lt mx-auto mb-3" style="width:80px;height:80px">
        <i class="ti ti-check" style="font-size:2.5rem;color:#0d9668"></i>
      </div>
      <h2>Campanha {{ scheduleMode === 'schedule' ? 'agendada' : 'enviada' }}!</h2>
      <p class="text-muted">{{ sentCampaignName }}</p>
    </div>
    <div class="d-flex gap-2 justify-content-center">
      <button class="btn btn-primary" @click="router.push(`/campaigns/${campaignId}`)">
        <i class="ti ti-eye me-1"></i> Ver campanha
      </button>
      <button class="btn btn-outline-primary" @click="router.push('/campaigns/new')">
        <i class="ti ti-plus me-1"></i> Criar outra
      </button>
    </div>
  </div>

  <div v-if="!sendSuccess" class="row row-cards">
    <div class="col-12">
      <div class="card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">{{ campaignId ? `#${campaignId}` : 'Nova' }}</span>
            <span v-if="isSaving" class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Salvando...</span>
            <span v-else-if="saveError" class="text-danger small" :title="saveError">
              <i class="ti ti-alert-circle me-1"></i>Falha ao salvar — clique
              <a href="#" @click.prevent="autosave()" class="text-danger" style="text-decoration:underline">tentar novamente</a>
            </span>
            <span v-else-if="lastSaved" class="text-muted small">
              <i class="ti ti-check text-success me-1"></i>Salvo
              <span v-if="lastSavedAt" class="ms-1">às {{ lastSavedAt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }}</span>
            </span>
          </div>
        </div>
        <div class="card-body">
          <!-- Step indicator -->
          <div class="d-flex align-items-center gap-2 mb-4 bc-steps-bar">
            <template v-for="(label, i) in stepLabels" :key="i">
              <button class="bc-step-pill" :class="{
                'bc-step-active': currentStep === i + 1,
                'bc-step-done': currentStep > i + 1,
                'bc-step-future': currentStep < i + 1
              }" @click="goToStep(i + 1)" :disabled="currentStepMax < i + 1">
                <span class="bc-step-num">{{ i + 1 }}</span>
                <span class="bc-step-label">{{ label }}</span>
              </button>
              <i v-if="i < stepLabels.length - 1" class="ti ti-chevron-right" style="color:var(--bc-text-muted);font-size:0.75rem;opacity:0.3"></i>
            </template>
          </div>

          <!-- Step 1: Identity + Content -->
          <div v-show="currentStep === 1">
            <!-- Hero: Campaign name (pré-criação — pega a tela inteira para focar no nome) -->
            <div v-if="!campaignId" class="text-center py-4 mb-4">
              <div class="mb-3">
                <div style="width:56px;height:56px;border-radius:14px;background:var(--bc-gray-soft);display:inline-flex;align-items:center;justify-content:center">
                  <i :class="`ti ${channelIcon}`" style="font-size:1.5rem;color:var(--bc-primary-text)"></i>
                </div>
              </div>
              <h2 style="font-family:'Manrope',sans-serif;font-weight:800;font-size:1.5rem;letter-spacing:-0.02em">Etapa 1: Identidade da Campanha</h2>
              <p style="color:var(--bc-text-muted);font-size:0.85rem">Defina um nome memorável para sua campanha de {{ channelLabel }}</p>
              <div style="max-width:520px;margin:0 auto">
                <input type="text" class="form-control form-control-lg text-center"
                  v-model="form.name"
                  :class="{ 'is-invalid': !form.name && hasTriedAdvance }"
                  :placeholder="`Ex: Summer Flash Sale ${new Date().getFullYear()}`"
                  style="font-size:1.1rem;border-radius:14px;background:var(--bc-gray-soft);border:none;padding:0.85rem 1.5rem"
                  autofocus>
                <div v-if="!form.name && hasTriedAdvance" class="invalid-feedback d-block">Nome da campanha é obrigatório.</div>
              </div>
            </div>

            <!-- Compact header: pós-criação — nome editável inline, libera espaço pro briefing -->
            <div v-else class="d-flex align-items-center gap-2 mb-4 flex-wrap">
              <span class="badge" style="background:var(--bc-gray-soft);color:var(--bc-text-muted);font-size:0.7rem;padding:0.35em 0.75em;border-radius:9999px">01</span>
              <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1.05rem;margin:0">Identidade</h3>
              <input type="text" class="form-control form-control-sm"
                v-model="form.name"
                @blur="onSaveField('name', form.name)"
                @keydown.enter.prevent="(e) => (e.target as HTMLInputElement).blur()"
                aria-label="Nome da campanha"
                style="max-width:320px;background:var(--bc-gray-soft);border:none;font-weight:600">
              <span class="badge" style="background:var(--bc-primary-subtle);color:var(--bc-primary-text);font-size:0.68rem;border-radius:9999px">CANAL {{ form.type.toUpperCase() }}</span>
            </div>

            <!-- Content section (below name) -->
            <div v-if="campaignId" class="mt-2">
              <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge" style="background:var(--bc-gray-soft);color:var(--bc-text-muted);font-size:0.7rem;padding:0.35em 0.75em;border-radius:9999px">02</span>
                <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1.1rem;margin:0">Detalhes do Briefing</h3>
              </div>
              <Step2WhatsApp
                v-if="form.type === 'whatsapp'"
                v-model="whatsappSettings"
                @update:valid="whatsappValid = $event"
              />
              <Step2Content
                v-else
                :campaign-id="campaignId"
                :channel="form.type"
                :content="form.content"
                :subject="form.subject"
                :audio-url="form.audio_url"
                :strategy-locked="strategyLocked"
                @save="onSaveField"
              />
            </div>
          </div>

          <!-- Step 2: Audience + Timing -->
          <div v-show="currentStep === 2">
            <div class="row g-4">
              <!-- Left: Audience + Schedule (8/12) -->
              <div class="col-lg-8">
                <!-- Audience Selection -->
                <div class="mb-4">
                  <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge" style="background:var(--bc-gray-soft);color:var(--bc-text-muted);font-size:0.7rem;padding:0.35em 0.75em;border-radius:9999px">03</span>
                    <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1.1rem;margin:0">Seleção de Audiência</h3>
                  </div>
                  <Step3Contacts
                    :channel="form.type"
                    v-model="form.contact_list_id"
                    @update:valid="step3Valid = $event"
                    @update:adhoc-phones="onAdhocPhonesChange"
                    @update:source="contactSource = $event"
                    @update:contacts-count="onContactsCountChange"
                  />
                </div>

                <!-- Campaign Timing -->
                <div>
                  <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge" style="background:var(--bc-gray-soft);color:var(--bc-text-muted);font-size:0.7rem;padding:0.35em 0.75em;border-radius:9999px">04</span>
                    <h3 style="font-family:'Manrope',sans-serif;font-weight:700;font-size:1.1rem;margin:0">Agendamento</h3>
                  </div>
                  <Step4Schedule :initial-mode="scheduleMode" :initial-date-time="scheduleAt" @update="onScheduleUpdate" />
                </div>
              </div>

              <!-- Right: Campaign Summary sidebar (4/12) -->
              <div class="col-lg-4">
                <div class="card glass-card sticky-top" style="top:1rem">
                  <div class="card-header">
                    <h3 class="card-title mb-0" style="font-family:'Manrope',sans-serif;font-weight:700;font-size:0.95rem">Resumo da Campanha</h3>
                  </div>
                  <div class="card-body">
                    <div class="mb-3">
                      <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--bc-text-muted);margin-bottom:0.25rem">Total de Destinatários</div>
                      <div style="font-family:'Manrope',sans-serif;font-weight:800;font-size:2rem;letter-spacing:-0.03em">{{ (contactsTotal || adhocPhones.length).toLocaleString('pt-BR') }}</div>
                    </div>
                    <div class="mb-3">
                      <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--bc-text-muted);margin-bottom:0.25rem">Custo Total Estimado</div>
                      <div style="font-family:'Manrope',sans-serif;font-weight:800;font-size:2rem;letter-spacing:-0.03em">
                        <span v-if="pricingLoading"><span class="spinner-border spinner-border-sm"></span></span>
                        <span v-else-if="pricingError || creditsPerSend === null" class="text-danger" style="font-size:0.85rem;font-weight:500">— Tarifa indisponível</span>
                        <span v-else>{{ brl((contactsTotal || adhocPhones.length) * creditsPerSend) }}</span>
                      </div>
                      <div style="font-size:0.72rem;color:var(--bc-text-muted)">
                        <template v-if="!pricingLoading && !pricingError && creditsPerSend !== null">{{ brl(creditsPerSend) }} por {{ form.type }}</template>
                      </div>
                    </div>
                    <div class="mb-3 pb-3" style="border-bottom:1px solid var(--bc-outline)">
                      <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--bc-text-muted);margin-bottom:0.25rem">Saldo Disponível</div>
                      <div
                        :title="currentBalance === -1 ? 'Saldo ilimitado (perfil administrativo). Esta conta não consome créditos.' : ''"
                        style="font-family:'Manrope',sans-serif;font-weight:800;font-size:1.5rem;color:var(--bc-success)"
                      >{{ currentBalance === -1 ? '∞' : brl(currentBalance) }}</div>
                      <div v-if="currentBalance === -1" class="small text-muted mt-1" style="font-size:0.7rem">Perfil administrativo — sem cobrança.</div>
                    </div>

                    <!-- Sem botões aqui: a navegação vive no rodapé do wizard
                         (evita 2 botões com o mesmo destino) e o rascunho é
                         persistido automaticamente pelo autosave. -->
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 3: Review + Launch -->
          <div v-show="currentStep === 3">
            <Step5Review
              :name="form.name"
              :type="form.type"
              status="draft"
              :content="form.content"
              :subject="form.subject"
              :audio-url="form.audio_url"
              :contact-source="contactSource"
              :contact-list-label="contactListLabel"
              :contacts-total="contactsTotal"
              :adhoc-phones="adhocPhones"
              :schedule-at="scheduleAt"
              :credits-per-send="creditsPerSend"
              :current-balance="currentBalance"
              @update:valid="step5Valid = $event"
            />
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
          <button class="btn btn-ghost-secondary" @click="prevStep" :disabled="currentStep === 1">
            <i class="ti ti-arrow-left me-1"></i> Voltar
          </button>
          <div class="d-flex gap-2">
            <button class="btn btn-link" @click="router.push('/campaigns')">Cancelar</button>
            <template v-if="currentStep < 3">
              <button class="btn btn-primary"
                @click="!campaignId ? advanceFromStep1() : nextStep()"
                :disabled="!canAdvance">
                <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
                {{ !campaignId ? 'Criar Campanha' : 'Avançar' }} <i class="ti ti-arrow-right ms-1"></i>
              </button>
            </template>
            <template v-else>
              <!-- O passo 3 já É a revisão/confirmação — dispara direto, sem modal redundante. -->
              <button v-if="scheduleMode === 'schedule' && scheduleAt" class="btn btn-primary" @click="finalSaveAndSchedule()" :disabled="isSending || !step5Valid">
                <span v-if="isSending" class="spinner-border spinner-border-sm me-2"></span>
                <i class="ti ti-calendar me-1"></i> Agendar Envio
              </button>
              <button v-else class="btn btn-primary btn-lg" @click="finalSaveAndSend()" :disabled="isSending || !step5Valid" style="background:linear-gradient(135deg,#0064ff,#0054d8);min-width:200px">
                <span v-if="isSending" class="spinner-border spinner-border-sm me-2"></span>
                <i class="ti ti-rocket me-1"></i> Enviar Campanha Agora
              </button>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import { useRouter, useRoute } from 'vue-router'
import { brl } from '@/utils/currency'
import { extractApiError } from '@/utils/apiError'
import Step4Schedule from '@/components/campaigns/steps/Step4Schedule.vue'
import Step5Review from '@/components/campaigns/steps/Step5Review.vue'
import Step2Content from '@/components/campaigns/steps/Step2Content.vue'
import Step2WhatsApp from '@/components/campaigns/steps/Step2WhatsApp.vue'
import Step3Contacts from '@/components/campaigns/steps/Step3Contacts.vue'
import { useAuthStore } from '@/stores/auth'

const { get, post, put } = useApi()
const toast = useToast()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

// === State ===
const isLoading = ref(false)
const isSaving = ref(false)
const saveError = ref<string | null>(null)
const lastSavedAt = ref<Date | null>(null)
const isSending = ref(false)
const step5Valid = ref(true)
const lastSaved = ref(false)
const sendSuccess = ref(false)
const sentCampaignName = ref('')
const step3Valid = ref(false)
const whatsappSettings = ref<Record<string, any> | null>(null)
const whatsappValid = ref(false)
const scheduleValid = ref(true)
const currentStep = ref(1)
const currentStepMax = ref(1)
const scheduleAt = ref<string | null>(null)
const scheduleMode = ref<'now' | 'schedule'>('now')
const strategyLocked = ref(false)
const adhocPhones = ref<string[]>([])
const contactSource = ref<'list' | 'file' | 'manual'>('list')
const contactListLabel = ref('-')
const contactsTotal = ref(0)
// Preço unitário REAL do canal — vem de /account/pricing (fonte única, P0-03).
// Antes era `ref(1)` literal → cartão "Custo Total Estimado" mostrava 1¢/envio
// independentemente da tarifa real cobrada pelo backend.
const creditsPerSend = ref<number | null>(null)
const pricingLoading = ref(false)
const pricingError = ref(false)

const form = ref({
  name: '',
  type: 'sms' as 'sms' | 'voice' | 'email' | 'whatsapp',
  content: '',
  subject: null as string | null,
  audio_url: '',
  contact_list_id: null as number | null,
})

// Recarrega tarifa sempre que o canal muda.
watch(() => form.value.type, async (channel) => {
  if (!channel) return
  pricingLoading.value = true
  pricingError.value = false
  try {
    const res = await get<any>('/account/pricing')
    const list = Array.isArray(res) ? res : (res?.data ?? [])
    const match = list.find((p: any) => p.service === channel)
    if (!match) {
      pricingError.value = true
      creditsPerSend.value = null
    } else {
      creditsPerSend.value = Number(match.sale_cents)
    }
  } catch {
    pricingError.value = true
    creditsPerSend.value = null
  } finally {
    pricingLoading.value = false
  }
}, { immediate: true })

const currentBalance = computed(() => {
  const tenant = auth.user?.tenant as any
  return tenant?.balance_cents ?? 0
})

const campaignId = computed<number | null>(() => {
  const id = route.query.id ?? route.query.edit
  return typeof id === 'string' ? parseInt(id, 10) : null
})

// === Channel display ===
const channelIcon = computed(() => ({ sms: 'ti-message', voice: 'ti-phone', email: 'ti-mail', whatsapp: 'ti-brand-whatsapp' }[form.value.type] ?? 'ti-speakerphone'))
const channelLabel = computed(() => ({ sms: 'SMS', voice: 'Torpedo de Voz', email: 'Email', whatsapp: 'WhatsApp' }[form.value.type] ?? form.value.type))
const channelDescription = computed(() => ({ sms: 'Mensagens de texto enviadas diretamente para o celular.', voice: 'Torpedo de voz com áudio gerado por IA.', email: 'Emails personalizados com HTML.', whatsapp: 'Mensagens via WhatsApp usando templates aprovados.' }[form.value.type] ?? ''))

const stepLabels = ['Identidade e Conteúdo', 'Audiência e Agenda', 'Revisão e Envio']

const hasTriedAdvance = ref(false)

const canAdvance = computed(() => {
  if (currentStep.value === 1) {
    // Need name + content
    if (!form.value.name || isLoading.value) return false
    if (!campaignId.value) return true // Allow creating campaign first
    if (form.value.type === 'whatsapp') return whatsappValid.value
    if (form.value.type === 'voice') return !!form.value.audio_url
    if (form.value.type === 'email') return !!form.value.subject && !!form.value.content
    return !!form.value.content
  }
  if (currentStep.value === 2) return step3Valid.value && scheduleValid.value
  return true
})

// === Auto-save debounced ===
let saveTimer: any = null

function autosave(fields?: Record<string, any>) {
  if (!campaignId.value) return
  clearTimeout(saveTimer)
  saveTimer = setTimeout(async () => {
    isSaving.value = true
    lastSaved.value = false
    saveError.value = null
    try {
      const payload: any = fields ?? buildFullPayload()
      await put(`/campaigns/${campaignId.value}`, payload)
      lastSaved.value = true
      lastSavedAt.value = new Date()
      setTimeout(() => { lastSaved.value = false }, 2000)
    } catch (e: any) {
      // P0-33: NÃO engolir erros. Cliente precisa saber que o auto-save falhou
      // para não pensar que a campanha está salva quando não está.
      const message = extractApiError(e, 'Não foi possível salvar automaticamente. Verifique sua conexão.')
      saveError.value = message
      toast.error(message)
    } finally {
      isSaving.value = false
    }
  }, 800) // 800ms debounce
}

function buildFullPayload() {
  const payload: any = {
    name: form.value.name,
    type: form.value.type,
    content: form.value.content || null,
    subject: form.value.subject || null,
    audio_url: form.value.audio_url || null,
    contact_list_id: form.value.contact_list_id,
    scheduled_at: scheduleAt.value,
  }
  // Settings: adhoc phones + whatsapp template
  const settings: any = {}
  if (adhocPhones.value.length > 0) {
    settings.adhoc_phones = adhocPhones.value
  }
  if (whatsappSettings.value) {
    Object.assign(settings, whatsappSettings.value)
  }
  if (Object.keys(settings).length > 0) {
    payload.settings = settings
  }
  // Estimated contacts
  if (contactSource.value !== 'list' && adhocPhones.value.length > 0) {
    payload.estimated_contacts = adhocPhones.value.length
  }
  return payload
}

// === Watch form changes for auto-save ===
watch(() => form.value.content, () => autosave({ content: form.value.content }))
watch(() => form.value.subject, () => autosave({ subject: form.value.subject }))
watch(() => form.value.audio_url, () => autosave({ audio_url: form.value.audio_url }))
watch(() => form.value.contact_list_id, (val) => {
  autosave({ contact_list_id: val })
})
watch(scheduleAt, () => autosave({ scheduled_at: scheduleAt.value }))
watch(adhocPhones, () => {
  if (adhocPhones.value.length > 0) {
    autosave({ settings: { adhoc_phones: adhocPhones.value }, estimated_contacts: adhocPhones.value.length })
  }
}, { deep: true })
watch(whatsappSettings, (val) => {
  if (val && campaignId.value) {
    autosave({ settings: val, content: `Template: ${val.template_name ?? ''}` })
  }
}, { deep: true })

// === Event handlers ===
function onSaveField(payload: { field: string; value: any }) {
  (form.value as any)[payload.field] = payload.value
  if (payload.field === 'strategy_locked') {
    strategyLocked.value = !!payload.value
    return // Don't autosave strategy_locked itself
  }
  autosave({ [payload.field]: payload.value })
}

function onAdhocPhonesChange(phones: string[]) {
  adhocPhones.value = phones
}

function onScheduleUpdate(payload: { mode: 'now' | 'schedule'; datetime: string | null; valid: boolean }) {
  scheduleMode.value = payload.mode
  scheduleAt.value = payload.datetime
  scheduleValid.value = payload.valid
}

function onContactsCountChange(count: number) {
  contactsTotal.value = count
}

// === Navigation ===
const goToStep = (n: number) => {
  if (n <= currentStepMax.value) {
    // Going backward is always allowed
    if (n <= currentStep.value) {
      currentStep.value = n
    } else {
      // Going forward: only if canAdvance from current step
      if (canAdvance.value) currentStep.value = n
    }
  }
}

const prevStep = () => {
  if (currentStep.value > 1) currentStep.value -= 1
}

const nextStep = async () => {
  clearTimeout(saveTimer)
  // Save current state before advancing
  if (campaignId.value) {
    try {
      await put(`/campaigns/${campaignId.value}`, buildFullPayload())
    } catch (e: any) {
      toast.error(extractApiError(e, 'Erro ao salvar. Tente novamente.'))
      return // Don't advance if save failed
    }
  }
  if (currentStep.value < 3) {
    currentStep.value += 1
    currentStepMax.value = Math.max(currentStepMax.value, currentStep.value)
  }
}

async function advanceFromStep1() {
  if (!form.value.name) { hasTriedAdvance.value = true; return }
  isLoading.value = true
  try {
    if (!campaignId.value) {
      const created = await post<any>('/campaigns', { name: form.value.name, type: form.value.type })
      if (created?.id) {
        router.replace({ path: '/campaigns/create', query: { id: String(created.id), channel: form.value.type } })
      } else {
        toast.error('Erro ao criar campanha.')
        return
      }
    } else {
      await put(`/campaigns/${campaignId.value}`, { name: form.value.name, type: form.value.type })
    }
    // Don't change step — content section will now appear since campaignId is set
  } catch (e: any) {
    toast.error(extractApiError(e, 'Erro ao criar campanha.'))
  } finally {
    isLoading.value = false
  }
}

// === Final actions ===

// Save all data first, then trigger action
async function saveBeforeAction(): Promise<boolean> {
  try {
    const payload = buildFullPayload()
    if (campaignId.value) {
      await put(`/campaigns/${campaignId.value}`, payload)
    }
    return true
  } catch (e: any) {
    toast.error(extractApiError(e, 'Erro ao salvar campanha'))
    return false
  }
}

async function finalSaveAndSend() {
  if (!campaignId.value) return
  isSending.value = true
  try {
    if (!await saveBeforeAction()) return
    await post(`/campaigns/${campaignId.value}/send-now`)
    sentCampaignName.value = form.value.name
    sendSuccess.value = true
  } catch (e: any) {
    toast.error(extractApiError(e, 'Erro ao disparar'))
  } finally {
    isSending.value = false
  }
}

async function finalSaveAndSchedule() {
  if (!campaignId.value || !scheduleAt.value) return
  isSending.value = true
  try {
    if (!await saveBeforeAction()) return
    await post(`/campaigns/${campaignId.value}/schedule`, { scheduled_at: scheduleAt.value })
    sentCampaignName.value = form.value.name
    sendSuccess.value = true
  } catch (e: any) {
    toast.error(extractApiError(e, 'Erro ao agendar'))
  } finally {
    isSending.value = false
  }
}

// === Init ===
if (typeof route.query.channel === 'string') {
  const ch = route.query.channel
  if (['sms', 'voice', 'email', 'whatsapp'].includes(ch)) {
    form.value.type = ch as any
  }
}
if (typeof route.query.preset_content === 'string' && route.query.preset_content.trim()) {
  form.value.content = route.query.preset_content
  strategyLocked.value = true
}

onMounted(async () => {
  const editId = route.query.edit ?? route.query.id
  if (editId) {
    try {
      const data = await get<any>(`/campaigns/${editId}`)
      if (!route.query.id) {
        router.replace({ path: '/campaigns/create', query: { id: String(data.id), channel: data.type } })
      }
      form.value = {
        name: data.name ?? '',
        type: data.type ?? 'sms',
        content: data.content ?? '',
        subject: data.subject ?? null,
        audio_url: data.audio_url ?? '',
        contact_list_id: data.contact_list_id ?? null,
      }
      if (data.scheduled_at) {
        scheduleAt.value = data.scheduled_at
        scheduleMode.value = 'schedule'
      }
      if (data.settings?.adhoc_phones) {
        adhocPhones.value = data.settings.adhoc_phones
        contactSource.value = 'manual'
      }
      if (data.settings?.template_name) {
        whatsappSettings.value = data.settings
      }
      // Allow navigating steps based on saved data
      if (data.content || data.settings?.template_name) currentStepMax.value = Math.max(currentStepMax.value, 1) // content is in step 1 now
      if (data.contact_list_id || data.settings?.adhoc_phones?.length) currentStepMax.value = Math.max(currentStepMax.value, 2)
      // Restore step position based on saved data
      if (currentStepMax.value > 1) {
        currentStep.value = currentStepMax.value
      }
    } catch {
      toast.error('Erro ao carregar campanha')
    }
  }
})
</script>

<style scoped>
/* Step bar: let the pills scroll horizontally instead of overflowing /
   squashing on narrow screens (375px can't fit all three labels). */
.bc-steps-bar {
  overflow-x: auto;
  scrollbar-width: none;
  -webkit-overflow-scrolling: touch;
}
.bc-steps-bar::-webkit-scrollbar { display: none; }
.bc-step-pill {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.85rem;
  border-radius: 9999px;
  border: none;
  background: var(--bc-gray-soft);
  color: var(--bc-text-muted);
  font-size: 0.78rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
}
.bc-step-pill:disabled { opacity: 0.4; cursor: not-allowed; }
.bc-step-pill:hover:not(:disabled) { background: var(--bc-primary-subtle); }
.bc-step-num {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.68rem;
  font-weight: 700;
  background: var(--bc-outline);
  color: var(--bc-text-muted);
}
.bc-step-active { background: var(--bc-primary-subtle); color: var(--bc-primary-text); }
.bc-step-active .bc-step-num { background: var(--bc-primary); color: #fff; }
.bc-step-done { background: rgba(16,185,129,0.08); color: var(--bc-success); }
.bc-step-done .bc-step-num { background: var(--bc-success); color: #fff; }
.bc-step-future {}
.bc-step-label { white-space: nowrap; }
@media (max-width: 576px) {
  .card-footer {
    flex-wrap: wrap;
    gap: 0.5rem;
  }
  .card-footer .btn {
    flex: 1 1 auto;
    min-width: 120px;
  }
}
</style>
