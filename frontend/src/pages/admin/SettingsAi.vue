<template>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Settings - IA / Grok</h2>
      <span style="font-size:0.82rem;color:var(--bc-text-muted)">Configuração da API Grok (xAI)</span>
    </div>
    <div class="d-flex gap-2">
    </div>
  </div>
      <div class="card" style="border-radius:14px">
        <div class="card-header"><h3 class="card-title">Configurações da API Grok (xAI)</h3></div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Grok API Key</label>
            <input type="password" class="form-control" v-model="form.grok_api_key" placeholder="xai-...">
            <small class="form-hint">Obtenha em console.x.ai</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Modelo</label>
            <select class="form-select" v-model="form.grok_model">
              <option v-if="models.length === 0" value="">Carregando modelos...</option>
              <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
            </select>
            <small class="form-hint">
              <a href="#" @click.prevent="fetchModels" class="text-primary">
                <i class="ti ti-refresh me-1"></i>Atualizar lista de modelos
              </a>
            </small>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
          <button class="btn btn-ghost-secondary" @click="testConnection" :disabled="isTesting">
            <span v-if="isTesting" class="spinner-border spinner-border-sm me-1"></span>
            Testar conexão
          </button>
          <button class="btn btn-primary" @click="save" :disabled="isSaving">
            <span v-if="isSaving" class="spinner-border spinner-border-sm me-1"></span>
            Salvar
          </button>
        </div>
      </div>

      <!-- Modelos disponíveis -->
      <div class="card mt-3" v-if="models.length" style="border-radius:14px">
        <div class="card-header">
          <h3 class="card-title">Modelos disponíveis ({{ models.length }})</h3>
        </div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th>ID do modelo</th>
                <th style="width:100px">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in models" :key="m">
                <td>{{ m }}</td>
                <td>
                  <span :class="['badge', m === form.grok_model ? 'bg-green' : 'bg-secondary']">
                    {{ m === form.grok_model ? 'Ativo' : 'Disponível' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put, post } = useApi()
const toast = useToast()

const form = ref({ grok_api_key: '', grok_model: 'grok-beta' })
const models = ref<string[]>([])
const isSaving = ref(false)
const isTesting = ref(false)

async function loadSettings() {
  try {
    const data = await get<any>('/admin/settings/ai')
    form.value.grok_model = data?.grok_model ?? 'grok-beta'
    if (data?.has_api_key) {
      form.value.grok_api_key = '********'
    }
  } catch {}
}

async function fetchModels() {
  try {
    const data = await get<any>('/admin/settings/ai/models')
    models.value = Array.isArray(data) ? data : []
    if (models.value.length) {
      toast.success(`${models.value.length} modelos encontrados`)
    }
  } catch {
    toast.error('Falha ao buscar modelos. Verifique a API Key.')
  }
}

async function testConnection() {
  isTesting.value = true
  try {
    const apiKey = form.value.grok_api_key !== '********' ? form.value.grok_api_key : undefined
    const res = await post<any>('/admin/settings/ai/test', { api_key: apiKey })
    toast.success('Conexão OK!')
    // Aproveita os modelos retornados pelo teste
    if (res?.models?.length) {
      models.value = res.models
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Falha no teste de conexão')
  } finally {
    isTesting.value = false
  }
}

async function save() {
  isSaving.value = true
  try {
    const payload: any = { grok_model: form.value.grok_model }
    if (form.value.grok_api_key && form.value.grok_api_key !== '********') {
      payload.grok_api_key = form.value.grok_api_key
    }
    await put('/admin/settings/ai', payload)
    toast.success('Configurações salvas!')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
  } finally {
    isSaving.value = false
  }
}

onMounted(async () => {
  await loadSettings()
  await fetchModels()
})
</script>
