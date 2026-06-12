<template>
  <div>
    <!-- Toggle IA / Manual -->
    <div class="form-selectgroup mb-4">
      <label class="form-selectgroup-item">
        <input type="radio" class="form-selectgroup-input" value="ai" v-model="mode">
        <span class="form-selectgroup-label">
          <i class="ti ti-sparkles me-2 text-primary"></i>Gerar com IA
        </span>
      </label>
      <label class="form-selectgroup-item">
        <input type="radio" class="form-selectgroup-input" value="manual" v-model="mode">
        <span class="form-selectgroup-label">
          <i class="ti ti-pencil me-2"></i>Escrever manualmente
        </span>
      </label>
    </div>

    <!-- ═══ MODO MANUAL ═══ -->
    <div v-if="mode === 'manual'" class="row g-4">
      <div class="col-lg-5">
        <div class="card sticky-top" style="top:1rem">
          <div class="card-header">
            <h3 class="card-title mb-0"><i class="ti ti-pencil me-2"></i>Roteiro manual</h3>
          </div>
          <div class="card-body">
            <textarea class="form-control mb-2" rows="8" v-model="script"
              placeholder="Escreva o roteiro que sera convertido em audio..."></textarea>
            <div class="d-flex justify-content-between mb-3">
              <span class="text-muted small">{{ wordCount(script) }} palavras · {{ durationEstimate }}s</span>
              <button class="btn btn-sm btn-outline-primary" :disabled="!script || ai.isAnalyzing" @click="analyzeScript">
                <i class="ti ti-brain me-1"></i>Analisar com IA
              </button>
            </div>

            <!-- Section: Escolher Voz (after script exists) -->
            <template v-if="script">
              <hr>
              <h4 class="mb-2" style="font-size:0.85rem;font-weight:600">
                <i class="ti ti-microphone me-1"></i> Escolher Voz
              </h4>
              <VoiceCards :voices="voices" :selected-voice="selectedVoice" :playing-preview="playingPreview"
                @select="selectedVoice = $event" @preview="previewVoice" />
              <audio ref="previewAudioEl" style="display:none" @ended="playingPreview = ''"></audio>
            </template>

            <!-- Section: Gerar Audio (after voice selected) -->
            <template v-if="script && selectedVoice">
              <hr>
              <h4 class="mb-2" style="font-size:0.85rem;font-weight:600">
                <i class="ti ti-volume me-1"></i> Gerar Audio
              </h4>
              <AudioEstimates :char-count="charCount" :duration="durationEstimate" :credits="creditsEstimate" />
              <button class="btn btn-primary w-100 mt-2" :disabled="ai.isGeneratingAudio || !props.campaignId"
                @click="onGenerateAudio">
                <span v-if="ai.isGeneratingAudio" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="ti ti-microphone me-2"></i>
                {{ ai.isGeneratingAudio ? 'Gerando audio...' : 'Gerar audio' }}
              </button>
              <div v-if="latestAudio" class="mt-3">
                <audio controls :src="latestAudio.audio_url" class="w-100"></audio>
                <button class="btn btn-success w-100 mt-2" @click="confirmAudio">
                  <i class="ti ti-check me-1"></i> Confirmar este audio
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
      <div class="col-lg-7">
        <!-- Audio history for manual mode -->
        <div v-if="ai.audioHistory.length" class="card">
          <div class="card-header">
            <h3 class="card-title mb-0"><i class="ti ti-microphone me-2"></i>Audios narrados</h3>
          </div>
          <div class="card-body">
            <div v-for="audio in ai.audioHistory" :key="audio.id"
              class="d-flex align-items-center gap-2 mb-2 p-2 rounded"
              style="border:1px solid var(--bc-gray,#e4e8ef)">
              <audio :src="audio.audio_url" controls style="height:32px;flex:1"></audio>
              <span class="text-muted" style="font-size:0.72rem">
                {{ audio.duration_seconds }}s · {{ audio.credits_charged }} cr
              </span>
              <button class="btn btn-outline-primary btn-sm" @click="useAudio(audio)">Usar</button>
            </div>
          </div>
        </div>
        <div v-else class="card">
          <div class="card-body text-center py-5">
            <i class="ti ti-microphone" style="font-size:3rem;color:#ddd"></i>
            <h3 class="text-muted mt-2">Escreva o roteiro e gere o audio</h3>
            <p class="text-muted">O texto sera convertido em voz usando ElevenLabs.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ MODO IA — Layout 2 colunas como SMS AiMode ═══ -->
    <div v-else class="row g-4">
      <!-- ESQUERDA: Briefing + Voz + Audio (col-lg-5) -->
      <div class="col-lg-5">
        <div class="card sticky-top" style="top:1rem">
          <div class="card-header">
            <h3 class="card-title mb-0"><i class="ti ti-sparkles me-2 text-primary"></i>Briefing</h3>
          </div>
          <div class="card-body">
            <!-- Section 1: Briefing fields -->
            <div class="mb-3">
              <label class="form-label required">Produto/Servico</label>
              <input class="form-control form-control-sm" v-model="briefing.product" placeholder="Ex: Academia FitLife">
            </div>
            <div class="mb-3">
              <label class="form-label required">Publico-alvo</label>
              <input class="form-control form-control-sm" v-model="briefing.audience" placeholder="Ex: Mulheres 25-40 anos">
            </div>
            <div class="mb-3">
              <label class="form-label required">Principal beneficio</label>
              <input class="form-control form-control-sm" v-model="briefing.benefit" placeholder="Ex: Perca 5kg em 30 dias">
            </div>
            <div class="mb-3">
              <label class="form-label required">Chamada para acao</label>
              <input class="form-control form-control-sm" v-model="briefing.cta" placeholder="Ex: Ligue agora">
            </div>
            <div class="row g-3 mb-3">
              <div class="col-7">
                <label class="form-label">Tom</label>
                <select class="form-select form-select-sm" v-model="briefing.tone">
                  <option value="professional">Profissional</option>
                  <option value="casual">Casual</option>
                  <option value="urgent">Urgente</option>
                  <option value="inspiring">Inspirador</option>
                  <option value="funny">Divertido</option>
                  <option value="direct">Direto</option>
                </select>
              </div>
              <div class="col-5">
                <label class="form-label">Versoes</label>
                <select class="form-select form-select-sm" v-model.number="variations">
                  <option v-for="n in [1,2,3,4,5]" :key="n" :value="n">{{ n }}</option>
                </select>
              </div>
            </div>

            <!-- Advanced options -->
            <div class="mb-3">
              <a class="text-muted small" href="#" @click.prevent="advancedOpen = !advancedOpen">
                <i :class="advancedOpen ? 'ti ti-chevron-up' : 'ti ti-chevron-down'" class="me-1"></i>
                Opcoes avancadas
              </a>
              <div v-if="advancedOpen" class="mt-2">
                <label class="form-label">Evitar palavras</label>
                <input class="form-control form-control-sm" v-model="avoidInput" placeholder="gratis, spam">
              </div>
            </div>

            <div class="form-hint mb-3">Ideal: 30-60 segundos (75-150 palavras). O roteiro sera convertido em audio.</div>

            <!-- Error with retry -->
            <div v-if="errorMessage" class="alert alert-warning d-flex align-items-center gap-2 mb-3">
              <i class="ti ti-alert-triangle"></i>
              <span>{{ errorMessage }}</span>
              <button class="btn btn-sm btn-warning ms-auto" @click="errorMessage = ''; generateWithAi()">
                <i class="ti ti-refresh me-1"></i> Tentar novamente
              </button>
            </div>

            <button class="btn btn-primary w-100"
              :disabled="!briefingValid || ai.isGenerating || !props.campaignId"
              @click="generateWithAi">
              <span v-if="ai.isGenerating" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="ti ti-sparkles me-2"></i>
              {{ ai.isGenerating ? 'Gerando...' : 'Gerar roteiros' }}
            </button>
            <div v-if="!props.campaignId" class="text-muted small text-center mt-2">
              <i class="ti ti-info-circle me-1"></i>Finalize o Step 1 para habilitar a geracao com IA
            </div>

            <!-- Section 2: Escolher Voz (only after script selected) -->
            <template v-if="script">
              <hr class="my-3">
              <h4 class="mb-2" style="font-size:0.85rem;font-weight:600">
                <i class="ti ti-microphone me-1"></i> Escolher Voz
              </h4>
              <div v-if="!voices.length" class="text-muted small mb-2">
                <span v-if="loadingVoices" class="spinner-border spinner-border-sm me-1"></span>
                {{ loadingVoices ? 'Carregando vozes...' : 'Nenhuma voz disponivel' }}
              </div>
              <div v-else class="d-flex flex-column gap-2 mb-3" style="max-height:240px;overflow-y:auto">
                <div v-for="v in voices" :key="v.voice_id"
                  class="d-flex align-items-center gap-2 p-2 rounded cursor-pointer"
                  :class="selectedVoice === v.voice_id ? 'border-primary bg-primary-lt' : ''"
                  style="border:1px solid var(--bc-gray,#e4e8ef);border-radius:8px"
                  @click="selectedVoice = v.voice_id">
                  <button class="btn btn-sm btn-icon"
                    :class="playingPreview === v.voice_id ? 'btn-primary' : 'btn-outline-primary'"
                    @click.stop="previewVoice(v.preview_url)"
                    style="width:32px;height:32px;border-radius:50%;flex-shrink:0">
                    <i class="ti"
                      :class="playingPreview === v.voice_id ? 'ti-player-pause' : 'ti-player-play'"
                      style="font-size:12px"></i>
                  </button>
                  <div class="flex-fill" style="min-width:0">
                    <div class="fw-medium" style="font-size:0.82rem">{{ v.name }}</div>
                    <div style="font-size:0.68rem;color:var(--bc-text-muted)">{{ v.language }} · {{ v.gender }}</div>
                  </div>
                  <i v-if="selectedVoice === v.voice_id" class="ti ti-circle-check-filled text-primary"
                    style="font-size:1.2rem"></i>
                </div>
              </div>
              <audio ref="previewAudioEl" style="display:none" @ended="playingPreview = ''"></audio>
            </template>

            <!-- Section 3: Gerar Audio (only after voice selected) -->
            <template v-if="script && selectedVoice">
              <hr class="my-3">
              <h4 class="mb-2" style="font-size:0.85rem;font-weight:600">
                <i class="ti ti-volume me-1"></i> Gerar Audio
              </h4>
              <AudioEstimates :char-count="charCount" :duration="durationEstimate" :credits="creditsEstimate" />
              <button class="btn btn-primary w-100 mt-2"
                :disabled="ai.isGeneratingAudio || !props.campaignId"
                @click="onGenerateAudio">
                <span v-if="ai.isGeneratingAudio" class="spinner-border spinner-border-sm me-2"></span>
                <i v-else class="ti ti-microphone me-2"></i>
                {{ ai.isGeneratingAudio ? 'Gerando audio...' : 'Gerar audio' }}
              </button>
              <div v-if="latestAudio" class="mt-3 p-3 rounded"
                style="background:rgba(16,185,129,0.04);border:1px solid rgba(16,185,129,0.15);border-radius:10px">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="badge" style="background:rgba(16,185,129,0.1);color:#0d9668">
                    <i class="ti ti-check me-1"></i>Audio pronto
                  </span>
                  <span style="font-size:0.72rem;color:var(--bc-text-muted)">
                    {{ latestAudio.duration_seconds }}s · {{ latestAudio.credits_charged }} cr
                  </span>
                </div>
                <audio controls :src="latestAudio.audio_url" class="w-100"></audio>
                <button class="btn btn-success w-100 mt-2" @click="confirmAudio">
                  <i class="ti ti-check me-1"></i> Confirmar este audio
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- DIREITA: Variacoes + Historico (col-lg-7) -->
      <div class="col-lg-7">
        <!-- Empty state -->
        <div v-if="!variationsList.length && !hasHistory" class="card">
          <div class="card-body text-center py-5">
            <div class="mb-3">
              <i class="ti ti-sparkles" style="font-size:3rem;color:#ddd"></i>
            </div>
            <h3 class="text-muted">Preencha o briefing e gere roteiros</h3>
            <p class="text-muted">A IA criara roteiros de voz personalizados para sua campanha.</p>
          </div>
        </div>

        <!-- Tabs: Geradas agora / Sessoes anteriores -->
        <div v-else>
          <ul class="nav nav-tabs mb-3" v-if="hasHistory || variationsList.length">
            <li class="nav-item">
              <a class="nav-link" :class="{ active: tab === 'current' }" href="#" @click.prevent="tab = 'current'">
                <i class="ti ti-sparkles me-1"></i>Geradas agora
                <span v-if="variationsList.length" class="badge bg-primary ms-1">{{ variationsList.length }}</span>
              </a>
            </li>
            <li class="nav-item" v-if="hasHistory">
              <a class="nav-link" :class="{ active: tab === 'history' }" href="#" @click.prevent="tab = 'history'">
                <i class="ti ti-history me-1"></i>Sessoes anteriores
                <span class="badge bg-secondary ms-1">{{ sessions.length }}</span>
              </a>
            </li>
          </ul>

          <!-- Tab: Current variations -->
          <div v-show="tab === 'current'">
            <div v-if="variationsList.length" class="d-flex align-items-center justify-content-between mb-2">
              <span class="text-muted small">Clique para selecionar o roteiro</span>
              <span class="badge bg-primary-lt text-primary">
                <i class="ti ti-bolt me-1"></i>{{ creditsUsed }} creditos
              </span>
            </div>

            <div class="d-flex flex-column gap-2">
              <div v-for="(v, i) in variationsList" :key="v.id"
                class="card cursor-pointer variation-card"
                :class="{ selected: selectedIndex === i }"
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
                      <span class="text-muted" style="font-size:0.72rem">
                        {{ wordCount(v.text) }} palavras · {{ durationEstimateFromText(v.text) }}s
                      </span>
                    </div>
                    <div class="flex-shrink-0 d-flex align-items-center gap-1">
                      <button class="btn btn-ghost-secondary btn-sm btn-icon" @click.stop="copyText(v.text)" title="Copiar">
                        <i class="ti ti-copy" style="font-size:14px"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <button class="btn btn-ghost-secondary btn-sm w-100 mt-2" :disabled="ai.isGenerating" @click="generateWithAi">
              <i class="ti ti-refresh me-1"></i> Regenerar
            </button>
          </div>

          <!-- Tab: Session history -->
          <div v-show="tab === 'history'">
            <div v-for="session in sessions" :key="session.id" class="card mb-2">
              <div class="card-header py-2">
                <div class="d-flex align-items-center justify-content-between w-100">
                  <span class="fw-medium small">Sessao #{{ session.id }}</span>
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

        <!-- Audio history (below variations) -->
        <div v-if="ai.audioHistory.length" class="mt-4">
          <h4 class="mb-2" style="font-size:0.85rem;font-weight:600">
            <i class="ti ti-microphone me-1"></i> Audios narrados
          </h4>
          <div v-for="audio in ai.audioHistory" :key="audio.id"
            class="d-flex align-items-center gap-2 mb-2 p-2 rounded"
            style="border:1px solid var(--bc-gray,#e4e8ef)">
            <audio :src="audio.audio_url" controls style="height:32px;flex:1"></audio>
            <span class="text-muted" style="font-size:0.72rem">
              {{ audio.duration_seconds }}s · {{ audio.credits_charged }} cr
            </span>
            <button class="btn btn-outline-primary btn-sm" @click="useAudio(audio)">Usar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useToast } from '@/composables/useToast'
import { useApi } from '@/composables/useApi'
import { useAiStore } from '@/stores/ai'

/* ── Inline sub-components ── */
const VoiceCards = {
  props: ['voices', 'selectedVoice', 'playingPreview'],
  emits: ['select', 'preview'],
  template: `
    <div class="d-flex flex-column gap-2 mb-3" style="max-height:240px;overflow-y:auto">
      <div v-for="v in voices" :key="v.voice_id"
        class="d-flex align-items-center gap-2 p-2 rounded cursor-pointer"
        :class="selectedVoice === v.voice_id ? 'border-primary bg-primary-lt' : ''"
        style="border:1px solid var(--bc-gray,#e4e8ef);border-radius:8px"
        @click="$emit('select', v.voice_id)">
        <button class="btn btn-sm btn-icon"
          :class="playingPreview === v.voice_id ? 'btn-primary' : 'btn-outline-primary'"
          @click.stop="$emit('preview', v.preview_url)"
          style="width:32px;height:32px;border-radius:50%;flex-shrink:0">
          <i class="ti"
            :class="playingPreview === v.voice_id ? 'ti-player-pause' : 'ti-player-play'"
            style="font-size:12px"></i>
        </button>
        <div class="flex-fill" style="min-width:0">
          <div class="fw-medium" style="font-size:0.82rem">{{ v.name }}</div>
          <div style="font-size:0.68rem;color:var(--bc-text-muted)">{{ v.language }} · {{ v.gender }}</div>
        </div>
        <i v-if="selectedVoice === v.voice_id" class="ti ti-circle-check-filled text-primary"
          style="font-size:1.2rem"></i>
      </div>
    </div>
  `,
}

const AudioEstimates = {
  props: ['charCount', 'duration', 'credits'],
  template: `
    <div class="row g-2 text-center">
      <div class="col-4">
        <div class="p-2 rounded" style="background:rgba(0,100,255,0.03)">
          <div style="font-size:0.68rem;color:var(--bc-text-muted);text-transform:uppercase">Caracteres</div>
          <div style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:1rem">{{ charCount }}</div>
        </div>
      </div>
      <div class="col-4">
        <div class="p-2 rounded" style="background:rgba(0,100,255,0.03)">
          <div style="font-size:0.68rem;color:var(--bc-text-muted);text-transform:uppercase">Duracao</div>
          <div style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:1rem">{{ duration }}s</div>
        </div>
      </div>
      <div class="col-4">
        <div class="p-2 rounded" style="background:rgba(0,100,255,0.03)">
          <div style="font-size:0.68rem;color:var(--bc-text-muted);text-transform:uppercase">Custo</div>
          <div style="font-family:'JetBrains Mono',monospace;font-weight:700;font-size:1rem">{{ credits }} cr</div>
        </div>
      </div>
    </div>
  `,
}

/* ── Props & Emits ── */
interface Props {
  campaignId?: number | null
  content?: string
}
const props = withDefaults(defineProps<Props>(), { campaignId: null, content: '' })
const emit = defineEmits<{ save: [payload: { field: string; value: any }] }>()

const ai = useAiStore()
const { get } = useApi()
const toast = useToast()

/* ── Mode toggle ── */
const mode = ref<'ai' | 'manual'>('ai')

/* ── Briefing fields ── */
const briefing = ref({
  product: '', audience: '', benefit: '', cta: '', tone: 'professional',
  avoid: [] as string[],
})
const avoidInput = ref('')
const advancedOpen = ref(false)
const variations = ref(3)
const errorMessage = ref('')

const briefingValid = computed(() => {
  const b = briefing.value
  return !!(b.product && b.audience && b.benefit && b.cta)
})

/* ── Script / variations ── */
const script = ref(props.content ?? '')
const variationsList = ref<Array<{ id: string; text: string }>>([])
const selectedIndex = ref<number | null>(null)
const creditsUsed = ref(0)

// Restore content prop on change
watch(() => props.content, (val) => {
  if (val && !script.value) script.value = val
})

// Autosave script content
watch(script, (val) => {
  emit('save', { field: 'content', value: val })
})

/* ── Session history ── */
const tab = ref<'current' | 'history'>('current')
const sessions = ref<any[]>([])
const hasHistory = computed(() => sessions.value.length > 0)

async function loadSessions() {
  if (!props.campaignId) return
  try {
    const resp = await get<any>(`/campaigns/${props.campaignId}/ai-sessions`)
    const data = Array.isArray(resp) ? resp : (resp?.data ?? [])
    sessions.value = data.filter((s: any) => s.channel === 'voice' && s.status === 'completed' && s.variations?.length)
  } catch {
    sessions.value = []
  }
}

/* ── Voice selection ── */
const voices = ref<Array<{
  voice_id: string; name: string; language?: string;
  preview_url?: string; gender?: string; accent?: string
}>>([])
const selectedVoice = ref('')
const playingPreview = ref('')
const previewAudioEl = ref<HTMLAudioElement | null>(null)
const loadingVoices = ref(false)

async function loadVoices() {
  loadingVoices.value = true
  try {
    const resp = await get<any>('/voices')
    voices.value = Array.isArray(resp) ? resp : (resp?.data ?? resp?.voices ?? [])
  } catch {
    toast.error('Falha ao carregar vozes')
  } finally {
    loadingVoices.value = false
  }
}

function previewVoice(url?: string) {
  if (!url) return
  const el = previewAudioEl.value
  if (!el) return
  const vid = voices.value.find(v => v.preview_url === url)?.voice_id ?? ''
  if (playingPreview.value === vid) {
    el.pause()
    playingPreview.value = ''
    return
  }
  el.src = url
  el.play()
  playingPreview.value = vid
}

/* ── Computed helpers ── */
const charCount = computed(() => script.value.length)
const durationEstimate = computed(() => durationEstimateFromText(script.value))

// Estimativa de créditos: lê o preço REAL do serviço audio_tts via /account/pricing.
// Antes era um literal Math.ceil(charCount * 0.5) que NÃO refletia a tarifa do tenant.
const audioTtsSaleCents = ref<number | null>(null)
async function loadAudioTtsPricing() {
  try {
    const res = await get<any>('/account/pricing')
    const list = Array.isArray(res) ? res : (res?.data ?? [])
    const match = list.find((p: any) => p.service === 'audio_tts')
    audioTtsSaleCents.value = match ? Number(match.sale_cents) : null
  } catch {
    audioTtsSaleCents.value = null
  }
}
onMounted(() => { loadAudioTtsPricing() })

// Retorna estimativa em centavos do TTS (charCount * sale_cents) ou null se tarifa indisponível.
const creditsEstimate = computed<number | null>(() => {
  if (audioTtsSaleCents.value === null) return null
  return charCount.value * audioTtsSaleCents.value
})

const latestAudio = computed(() => ai.audioHistory[0] ?? null)

// Velocidade média de fala em português (varia por voz; 150 wpm é a média de TTS comerciais).
// Mantido como constante visível para que a estimativa seja transparente — não é cobrança.
const ESTIMATED_WORDS_PER_MINUTE = 150
function durationEstimateFromText(text: string): number {
  return Math.ceil((wordCount(text) / ESTIMATED_WORDS_PER_MINUTE) * 60)
}

function wordCount(text: string): number {
  return text?.trim().match(/\b\w+\b/g)?.length ?? 0
}

/* ── AI generation ── */
async function generateWithAi() {
  if (!briefingValid.value) return
  errorMessage.value = ''
  try {
    briefing.value.avoid = avoidInput.value.split(',').map(s => s.trim()).filter(Boolean)
    const resp = await ai.generate({
      campaign_id: props.campaignId!,
      channel: 'voice',
      briefing: briefing.value,
      variations: variations.value,
    })
    variationsList.value = resp.variations ?? []
    creditsUsed.value = resp.credits_used ?? 0
    tab.value = 'current'
    loadSessions()
  } catch (e: any) {
    errorMessage.value = e?.response?.data?.message ?? 'Falha ao gerar roteiros. Tente novamente.'
  }
}

function selectVariation(i: number) {
  selectedIndex.value = i
  script.value = variationsList.value[i].text
  emit('save', { field: 'content', value: variationsList.value[i].text })
}

function selectFromHistory(text: string) {
  script.value = text
  emit('save', { field: 'content', value: text })
  tab.value = 'current'
  toast.success('Roteiro aplicado')
}

function copyText(text: string) {
  navigator.clipboard?.writeText(text)
  toast.info('Copiado')
}

/* ── Analyze script (manual mode) ── */
async function analyzeScript() {
  const res = await ai.analyze({ campaign_id: props.campaignId!, channel: 'voice', content: script.value })
  toast.success('Analise concluida')
  if (res.improved_versions?.length) script.value = res.improved_versions[0].text
}

/* ── Audio generation ── */
async function onGenerateAudio() {
  if (!props.campaignId) { toast.error('Salve a campanha primeiro (Step 1)'); return }
  const gen = await ai.generateAudio(props.campaignId, { script: script.value, voice_id: selectedVoice.value })
  emit('save', { field: 'audio_url', value: gen.audio_url })
  await ai.fetchAudio(props.campaignId)
}

function useAudio(audio: any) {
  emit('save', { field: 'audio_url', value: audio.audio_url })
  toast.success('Audio selecionado')
}

function confirmAudio() {
  if (latestAudio.value?.audio_url) {
    emit('save', { field: 'audio_url', value: latestAudio.value.audio_url })
    toast.success('Audio confirmado!')
  }
}

function formatDate(d: string) {
  try { return new Date(d).toLocaleString('pt-BR') } catch { return d }
}

/* ── Lifecycle ── */
watch(() => props.campaignId, (id) => {
  if (id) { loadSessions(); ai.fetchAudio(id) }
})

onMounted(async () => {
  await loadVoices()
  if (props.campaignId) {
    await Promise.all([loadSessions(), ai.fetchAudio(props.campaignId)])
  }
  // If we have variations from history but none loaded, check sessions
  if (variationsList.value.length === 0 && sessions.value.length > 0) {
    tab.value = 'history'
  }
})
</script>

<style scoped>
.variation-card {
  transition: all 0.15s ease;
  border: 1px solid #e0e5ec;
}
.variation-card:hover {
  border-color: #4299e1;
  box-shadow: 0 0 0 1px rgba(66, 153, 225, 0.2);
}
.variation-card.selected {
  border-color: #0064ff;
  border-width: 2px;
  background: #f0f7ff;
}
.variation-check {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid #ddd;
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
</style>
