<template>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Settings - WhatsApp</h2>
      <span style="font-size:0.82rem;color:var(--bc-text-muted)">Configuração Meta Cloud API</span>
    </div>
    <div class="d-flex gap-2">
    </div>
  </div>
  <div class="card" style="border-radius:14px">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Phone Number ID</label>
        <input class="form-control" v-model="form.phone_number_id" placeholder="ID do número no Meta" />
      </div>
      <div class="mb-3">
        <label class="form-label">WABA ID</label>
        <input class="form-control" v-model="form.waba_id" placeholder="WhatsApp Business Account ID" />
      </div>
      <div class="mb-3">
        <label class="form-label">Access Token</label>
        <input type="password" class="form-control" v-model="form.access_token" placeholder="********" />
      </div>
      <div class="mb-3">
        <label class="form-label">Verify Token <span class="text-muted">(global, para validar webhook)</span></label>
        <input class="form-control" v-model="form.verify_token" placeholder="Token customizado" />
      </div>
      <div class="mb-3">
        <label class="form-label">App Secret <span class="text-muted">(para validar assinatura)</span></label>
        <input type="password" class="form-control" v-model="form.app_secret" placeholder="********" />
      </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
      <div>
        <button class="btn btn-ghost-secondary me-2" @click="test" :disabled="isLoading">
          <span v-if="testing" class="spinner-border spinner-border-sm me-2"></span>
          Testar conexão
        </button>
        <button class="btn btn-ghost-info" @click="syncTemplates" :disabled="isLoading">
          <span v-if="syncing" class="spinner-border spinner-border-sm me-2"></span>
          Sincronizar templates
        </button>
      </div>
      <button class="btn btn-primary" @click="save" :disabled="isLoading">
        <span v-if="isLoading && !testing && !syncing" class="spinner-border spinner-border-sm me-2"></span>
        Salvar
      </button>
    </div>
  </div>

  <div v-if="templates.length" class="card mt-3" style="border-radius:14px">
    <div class="card-header"><div class="card-title">Templates aprovados ({{ templates.length }})</div></div>
    <div class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead><tr>
          <th>Nome</th><th>Idioma</th><th>Categoria</th><th>Status</th>
        </tr></thead>
        <tbody>
          <tr v-for="t in templates" :key="t.name + t.language">
            <td class="fw-bold">{{ t.name }}</td>
            <td>{{ t.language }}</td>
            <td>{{ t.category }}</td>
            <td><span class="badge bg-green">{{ t.status }}</span></td>
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
const isLoading = ref(false)
const testing = ref(false)
const syncing = ref(false)

const form = ref({ phone_number_id: '', waba_id: '', access_token: '', verify_token: '', app_secret: '' })
const templates = ref<any[]>([])

const load = async () => {
  isLoading.value = true
  try {
    const data = await get<any>('/admin/settings/whatsapp')
    form.value.phone_number_id = data?.phone_number_id ?? ''
    form.value.waba_id = data?.waba_id ?? ''
    form.value.verify_token = data?.verify_token ?? ''
    if (data?.has_access_token) form.value.access_token = '********'
  } finally { isLoading.value = false }
}

const save = async () => {
  isLoading.value = true
  try {
    const payload = { ...form.value }
    if (payload.access_token === '********') delete (payload as any).access_token
    if (payload.app_secret === '********' || !payload.app_secret) delete (payload as any).app_secret
    await put('/admin/settings/whatsapp', payload)
    toast.success('Salvo!')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao salvar') }
  finally { isLoading.value = false }
}

const test = async () => {
  testing.value = true; isLoading.value = true
  try {
    await post('/admin/settings/whatsapp/test', {})
    toast.success('Conexão OK')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Falha no teste') }
  finally { testing.value = false; isLoading.value = false }
}

const syncTemplates = async () => {
  syncing.value = true; isLoading.value = true
  try {
    const res = await post<any>('/admin/settings/whatsapp/sync-templates', {})
    templates.value = res?.templates ?? []
    toast.success(`${res?.count ?? 0} templates sincronizados`)
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao sincronizar') }
  finally { syncing.value = false; isLoading.value = false }
}

onMounted(load)
</script>
