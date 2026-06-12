<template>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Settings - ElevenLabs</h2>
      <span style="font-size:0.82rem;color:var(--bc-text-muted)">Text-to-Speech para campanhas de voz</span>
    </div>
    <div class="d-flex gap-2">
    </div>
  </div>
  <div class="card" style="border-radius:14px">
    <div class="card-body">
      <div class="alert alert-warning" v-if="!info?.has_api_key">
        <i class="ti ti-alert-circle me-2"></i> Configure a API Key para habilitar a sincronização de vozes.
      </div>
      <div class="mb-3">
        <label class="form-label">API Key</label>
        <input type="password" class="form-control" v-model="form.api_key" placeholder="********" />
      </div>
      <div class="mb-3">
        <label class="form-label">Model ID</label>
        <input class="form-control" v-model="form.model_id" placeholder="eleven_multilingual_v2" />
      </div>
      <div class="mb-3">
        <label class="form-label">Custo por caractere</label>
        <input class="form-control" v-model="form.cost_per_char" />
        <small class="form-hint">Estimativa: R$ {{ estimativaCusto }} por 1.000 caracteres</small>
      </div>
      <div class="mb-3">
        <label class="form-label">Preço por caractere</label>
        <input class="form-control" v-model="form.sale_per_char" />
        <small class="form-hint">Receita: R$ {{ estimativaPreco }} por 1.000 caracteres</small>
      </div>
      <div class="mb-3">
        <label class="form-label">Débito ao cliente por caractere (R$)</label>
        <input class="form-control" v-model="form.credits_per_char" />
        <small class="form-hint">Débito: R$ {{ estimativaCreditos }} por 1.000 caracteres</small>
      </div>
      <div class="mb-2 d-flex align-items-center">
        <span class="me-3"><span class="badge bg-azure">{{ info?.voices_count ?? 0 }}</span> vozes ativas</span>
        <span class="text-muted">Última sincronização: {{ info?.last_sync_at ? new Date(info.last_sync_at).toLocaleString() : '-' }}</span>
      </div>
      <div class="table-responsive" v-if="voices.length">
        <table class="table table-vcenter table-hover">
          <thead>
            <tr>
              <th>Voz</th>
              <th>Idioma</th>
              <th>Gênero</th>
              <th>Categoria</th>
              <th>Preview</th>
            </tr>
          </thead>
        <tbody>
          <tr v-for="v in voices" :key="v.voice_id">
            <td>{{ v.name }}</td>
            <td>{{ v.language ?? '-' }}</td>
            <td>{{ v.gender ?? '-' }}</td>
            <td>{{ v.category ?? '-' }}</td>
            <td>
              <audio v-if="v.preview_url" :src="v.preview_url" controls class="w-100"></audio>
              <span v-else class="text-muted">-</span>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <div class="d-flex gap-2">
        <button class="btn btn-ghost-secondary" @click="test" :disabled="isLoading">
          <span v-if="isLoading && testing" class="spinner-border spinner-border-sm me-2"></span>
          Testar conexão
        </button>
        <button class="btn btn-outline-primary" @click="sync" :disabled="isLoading">
          <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
          Sincronizar vozes
        </button>
      </div>
      <button class="btn btn-primary" @click="save" :disabled="isLoading">
        <span v-if="isLoading" class="spinner-border spinner-border-sm me-2"></span>
        Salvar
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put, post } = useApi()
const toast = useToast()
const isLoading = ref(false)
const testing = ref(false)

const info = ref<any>(null)
const form = ref<any>({
  api_key: '',
  model_id: 'eleven_multilingual_v2',
  cost_per_char: '0.0003',
  sale_per_char: '0.001',
  credits_per_char: '1',
})
const voices = ref<any[]>([])

const estimativaCusto = computed(() => {
  const v = parseFloat(form.value.cost_per_char || '0') * 1000
  return isNaN(v) ? '0,00' : v.toFixed(2)
})
const estimativaPreco = computed(() => {
  const v = parseFloat(form.value.sale_per_char || '0') * 1000
  return isNaN(v) ? '0,00' : v.toFixed(2)
})
const estimativaCreditos = computed(() => {
  const v = parseFloat(form.value.credits_per_char || '0') * 1000
  return isNaN(v) ? '0' : v.toFixed(0)
})

const loadVoices = async () => {
  try {
    voices.value = await get<any[]>('/admin/elevenlabs/voices') ?? []
  } catch { /* silent */ }
}

const load = async () => {
  isLoading.value = true
  try {
    const data = await get<any>('/admin/settings/elevenlabs')
    info.value = data
    Object.assign(form.value, data)
    await loadVoices()
  } finally {
    isLoading.value = false
  }
}

const save = async () => {
  isLoading.value = true
  try {
    const payload = { ...form.value }
    if (payload.api_key === '********' || payload.api_key === '') {
      delete (payload as any).api_key
    }
    await put('/admin/settings/elevenlabs', payload)
    toast.success('Salvo!')
  } catch (e:any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
  } finally {
    isLoading.value = false
  }
}

const sync = async () => {
  isLoading.value = true
  try {
    await post('/admin/elevenlabs/sync-voices')
    toast.success('Sincronização iniciada. Atualizando em 5 segundos...')
    // Reload voices after sync completes (async job)
    setTimeout(loadVoices, 5000)
  } catch (e:any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao sincronizar')
  } finally {
    isLoading.value = false
  }
}

const test = async () => {
  isLoading.value = true
  testing.value = true
  try {
    const api_key = form.value.api_key && form.value.api_key !== '********' ? form.value.api_key : '__USE_SAVED__'
    await post('/admin/settings/elevenlabs/test', { api_key })
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
