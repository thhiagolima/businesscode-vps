<template>
  <div class="row g-4">
    <!-- ESQUERDA: Briefing (40%) -->
    <div class="col-lg-5">
      <div class="card sticky-top" style="top:1rem">
        <div class="card-header">
          <h3 class="card-title"><i class="ti ti-sparkles me-2 text-primary"></i>Briefing</h3>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label required">Produto/Serviço</label>
            <input class="form-control form-control-sm" v-model="brief.product" placeholder="Ex: Repelente OFF!, App de delivery">
          </div>
          <div class="mb-3">
            <label class="form-label required">Público-alvo</label>
            <input class="form-control form-control-sm" v-model="brief.audience" placeholder="Ex: Campistas, mães 30-45 anos">
          </div>
          <div class="mb-3">
            <label class="form-label required">Principal benefício</label>
            <input class="form-control form-control-sm" v-model="brief.benefit" placeholder="Ex: Proteção 24h, Entrega em 30min">
          </div>
          <div class="mb-3">
            <label class="form-label required">Chamada para ação</label>
            <input class="form-control form-control-sm" v-model="brief.cta" placeholder="Ex: Compre agora com 50% OFF">
          </div>
          <div class="row g-3 mb-3">
            <div class="col-7">
              <label class="form-label">Tom</label>
              <select class="form-select form-select-sm" v-model="brief.tone">
                <option value="professional">Profissional</option>
                <option value="casual">Casual</option>
                <option value="urgent">Urgente</option>
                <option value="inspiring">Inspirador</option>
                <option value="funny">Divertido</option>
                <option value="direct">Direto</option>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label">Versões</label>
              <select class="form-select form-select-sm" v-model.number="variationsCount">
                <option v-for="n in [1,2,3,4,5]" :key="n" :value="n">{{ n }}</option>
              </select>
            </div>
          </div>

          <!-- Link (sempre visível, opcional) -->
          <div class="mb-3">
            <label class="form-label">Link <span class="text-muted fw-normal">(opcional)</span></label>
            <input type="url" class="form-control form-control-sm" v-model="brief.link" placeholder="https://seusite.com/oferta">
            <div class="form-hint">Se preenchido, será incluído na mensagem gerada.</div>
          </div>

          <!-- Avançado -->
          <div>
            <a class="text-muted small" href="#" @click.prevent="advancedOpen = !advancedOpen">
              <i :class="advancedOpen ? 'ti ti-chevron-up' : 'ti ti-chevron-down'" class="me-1"></i>
              Opções avançadas
            </a>
            <div v-if="advancedOpen" class="mt-2">
              <div class="mb-2">
                <label class="form-label">Evitar palavras</label>
                <input class="form-control form-control-sm" v-model="avoidRaw" placeholder="grátis, spam">
              </div>
              <label v-if="channel === 'sms'" class="form-check">
                <input class="form-check-input" type="checkbox" v-model="brief.max_chars160">
                <span class="form-check-label small">Limitar a 160 chars</span>
              </label>
            </div>
          </div>

          <!-- Erro de geração com retry -->
          <div v-if="errorMessage" class="alert alert-warning d-flex align-items-center gap-2 mt-3 mb-0">
            <i class="ti ti-alert-triangle"></i>
            <span>{{ errorMessage }}</span>
            <button class="btn btn-sm btn-warning ms-auto" @click="errorMessage = ''; generate()">
              <i class="ti ti-refresh me-1"></i> Tentar novamente
            </button>
          </div>

          <!-- Aviso sem link -->
          <div v-if="showNoLinkWarning" class="alert alert-warning mt-3 mb-0 py-2 d-flex align-items-center gap-2" style="font-size:0.82rem">
            <i class="ti ti-alert-triangle"></i>
            <div class="flex-fill">
              Nenhum link informado. A mensagem será gerada <strong>sem link</strong>.
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
              <button class="btn btn-sm btn-warning" :disabled="!props.campaignId" @click="confirmNoLink">Gerar sem link</button>
              <button class="btn btn-sm btn-outline-secondary" @click="showNoLinkWarning = false">Cancelar</button>
            </div>
          </div>

          <button v-if="!showNoLinkWarning" class="btn btn-primary w-100 mt-3" :disabled="!briefingValid || isGenerating || !props.campaignId" @click="handleGenerate">
            <span v-if="isGenerating" class="spinner-border spinner-border-sm me-2"></span>
            <i v-else class="ti ti-sparkles me-2"></i>
            {{ isGenerating ? 'Gerando...' : 'Gerar variações' }}
          </button>
          <div v-if="!props.campaignId" class="text-muted small text-center mt-2">
            <i class="ti ti-info-circle me-1"></i>Finalize o Step 1 para habilitar a geração com IA
          </div>
        </div>
      </div>
    </div>

    <!-- DIREITA: Variações + Histórico (60%) -->
    <div class="col-lg-7">
      <!-- Estado vazio: preview SMS + dicas -->
      <div v-if="!variations.length && !hasHistory" class="ai-empty">
        <!-- Preview do que será gerado (atualiza ao vivo conforme briefing) -->
        <div class="ai-empty__preview">
          <div class="ai-empty__device">
            <div class="ai-empty__device-notch"></div>
            <div class="ai-empty__bubble-header">
              <i class="ti ti-message-circle" style="font-size:0.9rem"></i>
              <span>Pré-visualização — {{ channelLabel }}</span>
            </div>
            <div class="ai-empty__bubble" :class="{ 'is-placeholder': !briefingValid }">
              <p class="mb-0">{{ previewMessage }}</p>
              <div v-if="previewCharCount" class="ai-empty__chars">
                <span :class="{ 'text-warning': previewCharCount > 160 }">{{ previewCharCount }}/160</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Dicas / Como funciona -->
        <div class="ai-empty__tips">
          <h4 class="ai-empty__tips-title">
            <i class="ti ti-sparkles me-2 text-primary"></i>Como a IA gera suas mensagens
          </h4>
          <ol class="ai-empty__steps">
            <li>
              <span class="ai-empty__step-num">1</span>
              <div>
                <strong>Você descreve</strong>
                <span class="text-muted">produto, público, benefício e chamada para ação</span>
              </div>
            </li>
            <li>
              <span class="ai-empty__step-num">2</span>
              <div>
                <strong>IA cria N versões</strong>
                <span class="text-muted">com tons diferentes, otimizadas para o canal</span>
              </div>
            </li>
            <li>
              <span class="ai-empty__step-num">3</span>
              <div>
                <strong>Você escolhe a melhor</strong>
                <span class="text-muted">e dispara — sem perder tempo escrevendo do zero</span>
              </div>
            </li>
          </ol>
          <div class="ai-empty__note">
            <i class="ti ti-bulb"></i>
            <span>Quanto mais específico o briefing, melhor o resultado. Quebra o público em "mães 30-45 anos" em vez de "geral".</span>
          </div>
        </div>
      </div>

      <!-- Tabs: Geradas agora / Sessões anteriores -->
      <div v-else>
        <ul class="nav nav-tabs mb-3" v-if="hasHistory || variations.length">
          <li class="nav-item">
            <a class="nav-link" :class="{ active: tab === 'current' }" href="#" @click.prevent="tab = 'current'">
              <i class="ti ti-sparkles me-1"></i>Geradas agora
              <span v-if="variations.length" class="badge bg-primary ms-1">{{ variations.length }}</span>
            </a>
          </li>
          <li class="nav-item" v-if="hasHistory">
            <a class="nav-link" :class="{ active: tab === 'history' }" href="#" @click.prevent="tab = 'history'">
              <i class="ti ti-history me-1"></i>Sessões anteriores
              <span class="badge bg-secondary ms-1">{{ sessions.length }}</span>
            </a>
          </li>
        </ul>

        <!-- Tab: Variações atuais -->
        <div v-show="tab === 'current'">
          <div v-if="variations.length" class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted small">Clique para selecionar</span>
            <span class="badge bg-primary-lt text-primary">
              <i class="ti ti-bolt me-1"></i>{{ brl(creditsUsed) }}
            </span>
          </div>

          <div class="d-flex flex-column gap-2">
            <div v-for="(v, i) in variations" :key="v.id"
                 class="card cursor-pointer variation-card"
                 :class="{ 'selected': selectedIndex === i }"
                 @click="selectVariation(i)">
              <div class="card-body py-2 px-3">
                <div class="d-flex align-items-start gap-2">
                  <div class="flex-shrink-0 mt-1">
                    <div class="variation-check" :class="{ active: selectedIndex === i }">
                      <i v-if="selectedIndex === i" class="ti ti-check" style="font-size:12px"></i>
                      <span v-else class="text-muted" style="font-size:11px">{{ i + 1 }}</span>
                    </div>
                  </div>
                  <div class="flex-fill">
                    <p class="mb-0" style="white-space:pre-wrap;font-size:0.85rem;line-height:1.5">{{ v.text }}</p>
                  </div>
                  <div class="flex-shrink-0 d-flex align-items-center gap-1">
                    <span v-if="channel === 'sms'" class="badge" :class="(v.text?.length ?? 0) > 160 ? 'bg-danger-lt' : 'bg-success-lt'" style="font-size:10px">
                      {{ v.text?.length ?? 0 }}/160
                    </span>
                    <button class="btn btn-ghost-secondary btn-sm btn-icon" @click.stop="copyText(v.text)" title="Copiar">
                      <i class="ti ti-copy" style="font-size:14px"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <button class="btn btn-ghost-secondary btn-sm w-100 mt-2" :disabled="isGenerating" @click="generate">
            <i class="ti ti-refresh me-1"></i> Regenerar
          </button>
        </div>

        <!-- Tab: Sessões anteriores -->
        <div v-show="tab === 'history'">
          <div v-for="session in sessions" :key="session.id" class="card mb-2">
            <div class="card-header py-2">
              <div class="d-flex align-items-center justify-content-between w-100">
                <span class="fw-medium small">Sessão #{{ session.id }}</span>
                <span class="text-muted" style="font-size:11px">{{ formatDate(session.created_at) }}</span>
              </div>
            </div>
            <div class="card-body py-0">
              <div v-for="(v, vi) in (session.variations || [])" :key="vi"
                   class="d-flex align-items-center justify-content-between py-2"
                   :class="{ 'border-top': vi > 0 }">
                <span style="font-size:0.82rem;white-space:pre-wrap" class="me-2">{{ v.text }}</span>
                <button class="btn btn-outline-primary btn-sm flex-shrink-0" @click="selectFromHistory(v.text)">
                  Usar
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import { brl } from '@/utils/currency'

interface Props {
  channel: 'sms'|'voice'|'email'
  campaignId?: number | null
}
const props = defineProps<Props>()
const emit = defineEmits<{ select: [{ text: string }] }>()
const { get, post } = useApi()
const toast = useToast()

const advancedOpen = ref(false)
const isGenerating = ref(false)
const errorMessage = ref('')
const selectedIndex = ref<number | null>(null)
const creditsUsed = ref(0)
const variations = ref<Array<{ id: string; text: string }>>([])
const tab = ref<'current'|'history'>('current')
const sessions = ref<any[]>([])
const hasHistory = computed(() => sessions.value.length > 0)

const brief = ref({
  product: '', audience: '', benefit: '', cta: '',
  tone: 'casual', link: '', avoid: [] as string[],
  max_chars160: props.channel === 'sms',
})
const avoidRaw = ref('')
const variationsCount = ref(3)

const showNoLinkWarning = ref(false)

const briefingValid = computed(() =>
  !!brief.value.product && !!brief.value.audience && !!brief.value.benefit && !!brief.value.cta
)

const channelLabel = computed(() => {
  const map: Record<string, string> = { sms: 'SMS', voice: 'Torpedo de Voz', email: 'Email', whatsapp: 'WhatsApp' }
  return map[props.channel] ?? props.channel.toUpperCase()
})

// Pré-visualização ao vivo da mensagem. Usa o briefing já preenchido para mostrar
// uma versão rascunho — quando vazio, mostra um exemplo placeholder.
const previewMessage = computed(() => {
  const b = brief.value
  if (!b.product && !b.audience && !b.benefit && !b.cta) {
    return 'Olá! Aproveite [benefício] em [produto]. [chamada para ação] → [link]'
  }
  const parts: string[] = []
  if (b.audience) parts.push(`Olá! Para ${b.audience.toLowerCase()}:`)
  else parts.push('Olá!')
  if (b.product && b.benefit) parts.push(`${b.product} com ${b.benefit.toLowerCase()}.`)
  else if (b.product) parts.push(`${b.product}.`)
  else if (b.benefit) parts.push(`${b.benefit}.`)
  if (b.cta) parts.push(b.cta + '.')
  if (b.link) parts.push(b.link)
  return parts.join(' ')
})

const previewCharCount = computed(() => briefingValid.value ? previewMessage.value.length : 0)

function handleGenerate() {
  if (!brief.value.link?.trim()) {
    showNoLinkWarning.value = true
    return
  }
  generate()
}

function confirmNoLink() {
  showNoLinkWarning.value = false
  generate()
}

async function generate() {
  showNoLinkWarning.value = false
  errorMessage.value = ''
  try {
    isGenerating.value = true
    selectedIndex.value = null
    brief.value.avoid = avoidRaw.value.split(',').map(s => s.trim()).filter(Boolean)
    const resp = await post<any>('/ai/generate', {
      campaign_id: props.campaignId ?? null,
      channel: props.channel,
      briefing: {
        product: brief.value.product,
        audience: brief.value.audience,
        benefit: brief.value.benefit,
        cta: brief.value.cta,
        tone: brief.value.tone,
        link: brief.value.link || undefined,
        avoid: brief.value.avoid,
        max_chars: brief.value.max_chars160 ? 160 : undefined,
      },
      variations: variationsCount.value,
    })
    creditsUsed.value = resp?.credits_used ?? 0
    variations.value = (resp?.variations ?? []).map((v: any) => ({ id: v.id, text: v.text }))
    tab.value = 'current'
    toast.success(`${variations.value.length} variações geradas`)
    // Refresh sessions
    loadSessions()
  } catch (e: any) {
    errorMessage.value = e?.response?.data?.message ?? 'Falha ao gerar com IA. Verifique sua conexão e tente novamente.'
  } finally {
    isGenerating.value = false
  }
}

function selectVariation(i: number) {
  selectedIndex.value = i
  emit('select', { text: variations.value[i].text })
}

function selectFromHistory(text: string) {
  emit('select', { text })
  tab.value = 'current'
  toast.success('Variação aplicada')
}

function copyText(text: string) {
  navigator.clipboard?.writeText(text)
  toast.info('Copiado')
}

async function loadSessions() {
  if (!props.campaignId) return
  try {
    const resp = await get<any>(`/campaigns/${props.campaignId}/ai-sessions`)
    const data = Array.isArray(resp) ? resp : (resp?.data ?? [])
    sessions.value = data.filter((s: any) => s.status === 'completed' && s.variations?.length)
  } catch {
    sessions.value = []
  }
}

function formatDate(d: string) {
  try { return new Date(d).toLocaleString('pt-BR') } catch { return d }
}

watch(() => props.campaignId, (id) => { if (id) loadSessions() })
onMounted(() => { if (props.campaignId) loadSessions() })
</script>

<style scoped>
.variation-card {
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
  border: 1px solid var(--bc-outline);
}
.variation-card:hover {
  border-color: var(--bc-primary-text);
  box-shadow: 0 0 0 1px var(--bc-primary-glow);
}
/* Selected state must stay legible on the dark theme — the old #f0f7ff
   background put light text on a near-white card (invisible). */
.variation-card.selected {
  border-color: var(--bc-primary-text);
  border-width: 2px;
  background: var(--bc-primary-subtle);
}
.variation-check {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid var(--bc-text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s;
}
.variation-check.active {
  border-color: #0064ff;
  background: #0064ff;
  color: #fff;
}

/* ─── Empty state preview (Step 1 Briefing direita) ─── */
.ai-empty {
  display: grid;
  gap: 1.25rem;
}
@media (min-width: 992px) {
  .ai-empty { grid-template-columns: minmax(280px, 360px) 1fr; }
}

.ai-empty__preview {
  display: flex;
  justify-content: center;
}
.ai-empty__device {
  position: relative;
  width: 100%;
  max-width: 320px;
  background: var(--bc-gray);
  border-radius: 24px;
  padding: 18px 14px 16px;
  border: 1px solid var(--bc-outline);
}
.ai-empty__device-notch {
  position: absolute;
  top: 8px;
  left: 50%;
  transform: translateX(-50%);
  width: 60px;
  height: 5px;
  border-radius: 9999px;
  background: rgba(255,255,255,0.08);
}
.ai-empty__bubble-header {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.7rem;
  color: var(--bc-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin: 0.5rem 0 0.75rem;
  font-weight: 600;
}
.ai-empty__bubble {
  background: var(--bc-primary-subtle);
  color: var(--bc-text);
  border-radius: 16px 16px 16px 4px;
  padding: 0.85rem 1rem;
  font-size: 0.88rem;
  line-height: 1.55;
  position: relative;
}
.ai-empty__bubble.is-placeholder {
  color: var(--bc-text-muted);
  font-style: italic;
  background: var(--bc-gray-soft);
}
.ai-empty__chars {
  margin-top: 0.5rem;
  font-size: 0.7rem;
  font-family: 'JetBrains Mono', monospace;
  color: var(--bc-text-muted);
  text-align: right;
}

.ai-empty__tips {
  background: var(--bc-gray);
  border-radius: 14px;
  padding: 1.25rem;
}
.ai-empty__tips-title {
  font-family: 'Manrope', sans-serif;
  font-size: 0.95rem;
  font-weight: 700;
  margin: 0 0 1rem;
  color: var(--bc-text);
}
.ai-empty__steps {
  list-style: none;
  padding: 0;
  margin: 0 0 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}
.ai-empty__steps li {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}
.ai-empty__step-num {
  flex-shrink: 0;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--bc-primary-subtle);
  color: var(--bc-primary-text);
  font-weight: 700;
  font-size: 0.78rem;
  display: flex;
  align-items: center;
  justify-content: center;
}
.ai-empty__steps li > div {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.85rem;
  line-height: 1.4;
}
.ai-empty__steps strong { color: var(--bc-text); font-weight: 600; }

.ai-empty__note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.65rem 0.85rem;
  background: rgba(0, 100, 255, 0.06);
  border-left: 2px solid var(--bc-primary);
  border-radius: 6px;
  font-size: 0.78rem;
  color: var(--bc-text-muted);
  line-height: 1.45;
}
.ai-empty__note i {
  color: var(--bc-primary-text);
  margin-top: 0.15rem;
  flex-shrink: 0;
}
</style>
