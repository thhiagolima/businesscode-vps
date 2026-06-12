<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">Tokens de API</h3>
        <span style="font-size:0.78rem;color:var(--bc-text-muted)">
          Tokens server-to-server criados pelos usuários deste tenant.
        </span>
      </div>
      <button class="btn btn-primary" :disabled="loadingUsers" @click="openCreate">
        <i class="ti ti-plus me-1"></i>Gerar novo token
      </button>
    </div>

    <div class="card" style="border-radius:14px">
      <div v-if="loading" class="card-body text-center py-4">
        <span class="spinner-border spinner-border-sm"></span>
      </div>
      <div v-else-if="!items.length" class="card-body text-center py-4 text-muted">
        <i class="ti ti-key" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:0.5rem"></i>
        Nenhum token ativo para este tenant.
      </div>
      <div v-else class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr>
              <th>Usuário</th>
              <th>Nome</th>
              <th>Abilities</th>
              <th>Último uso</th>
              <th>Expira</th>
              <th>Criado</th>
              <th class="w-1"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in items" :key="t.id">
              <td>
                <div class="fw-medium">{{ t.user?.name ?? '—' }}</div>
                <div style="font-size:0.72rem;color:var(--bc-text-muted)">{{ t.user?.email }}</div>
              </td>
              <td>{{ t.name }}</td>
              <td>
                <span v-for="a in (t.abilities ?? [])" :key="a" class="badge bg-secondary-lt me-1" style="font-size:0.65rem">
                  {{ a }}
                </span>
              </td>
              <td style="font-size:0.78rem">{{ formatDate(t.last_used_at) || 'Nunca' }}</td>
              <td style="font-size:0.78rem">{{ formatDate(t.expires_at) || '—' }}</td>
              <td class="text-muted" style="font-size:0.78rem">{{ formatDate(t.created_at) }}</td>
              <td class="text-end">
                <button class="btn btn-sm btn-icon btn-ghost-danger" @click="revoke(t)" title="Revogar">
                  <i class="ti ti-trash"></i>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: criar token -->
    <div v-if="createOpen" class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.4)">
      <div class="modal-dialog modal-md">
        <div class="modal-content" style="border-radius:14px">
          <div class="modal-header">
            <h5 class="modal-title"><i class="ti ti-key me-2"></i>Gerar novo token de API</h5>
            <button type="button" class="btn-close" @click="createOpen = false"></button>
          </div>
          <form @submit.prevent="create">
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label required">Usuário</label>
                <select v-model="form.user_id" class="form-select" style="border-radius:10px" required>
                  <option value="" disabled>Selecione um usuário do tenant</option>
                  <option v-for="u in users" :key="u.id" :value="u.id">
                    {{ u.name }} — {{ u.email }} ({{ u.role }})
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label required">Nome</label>
                <input v-model="form.name" class="form-control" style="border-radius:10px"
                       placeholder="Ex: Integração ERP" required maxlength="120" />
              </div>
              <div class="mb-3">
                <label class="form-label required">Abilities</label>
                <div class="d-flex flex-wrap gap-2">
                  <label v-for="opt in abilityOptions" :key="opt.value" class="form-check m-0" style="font-size:0.85rem">
                    <input class="form-check-input" type="checkbox" :value="opt.value" v-model="form.abilities" />
                    <span class="form-check-label ms-1">
                      <code style="font-size:0.78rem">{{ opt.value }}</code>
                      <span class="text-muted ms-1" style="font-size:0.72rem">{{ opt.label }}</span>
                    </span>
                  </label>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">Expira em (opcional)</label>
                <input v-model="form.expires_at" type="datetime-local" class="form-control" style="border-radius:10px" />
                <div class="form-hint" style="font-size:0.72rem">Deixe vazio para token sem expiração.</div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost-secondary" @click="createOpen = false">Cancelar</button>
              <button type="submit" class="btn btn-primary" :disabled="saving || !form.user_id || !form.abilities.length">
                <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
                Gerar token
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal: resultado (mostra token 1x) -->
    <div v-if="newTokenValue" class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.4)">
      <div class="modal-dialog modal-md">
        <div class="modal-content" style="border-radius:14px">
          <div class="modal-header">
            <h5 class="modal-title"><i class="ti ti-key me-2"></i>Token gerado</h5>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning" style="border-radius:12px">
              <i class="ti ti-alert-triangle me-1"></i>
              <strong>Este token será exibido apenas uma vez.</strong> Copie agora.
            </div>
            <div class="d-flex align-items-center gap-2 my-3">
              <input :value="newTokenValue" readonly class="form-control" style="font-family:'JetBrains Mono',monospace;font-size:0.78rem" />
              <button class="btn btn-primary" @click="copyToken">
                <i class="ti ti-copy me-1"></i>Copiar
              </button>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" :disabled="!copied" @click="closeTokenModal">
              {{ copied ? 'Entendi' : 'Copie antes de fechar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ tenantId: number }>()
const { get, post, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const users = ref<any[]>([])
const loading = ref(false)
const loadingUsers = ref(false)
const saving = ref(false)
const createOpen = ref(false)
const newTokenValue = ref<string | null>(null)
const copied = ref(false)

const abilityOptions = [
  { value: '*',                label: 'Acesso total' },
  { value: 'campaigns.send',   label: 'Disparar campanhas' },
  { value: 'contacts.write',   label: 'Criar/atualizar contatos' },
  { value: 'contacts.read',    label: 'Listar contatos' },
  { value: 'reports.read',     label: 'Consultar relatórios' },
  { value: 'webhooks.manage',  label: 'Gerenciar webhooks' },
]

const form = reactive({
  user_id: '' as number | string,
  name: '',
  abilities: [] as string[],
  expires_at: '',
})

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/api-tokens`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function loadUsers() {
  loadingUsers.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/users`)
    users.value = resp.data ?? []
  } finally {
    loadingUsers.value = false
  }
}

function openCreate() {
  form.user_id = ''
  form.name = ''
  form.abilities = []
  form.expires_at = ''
  if (!users.value.length) loadUsers()
  createOpen.value = true
}

async function create() {
  saving.value = true
  try {
    const payload: any = {
      user_id: Number(form.user_id),
      name: form.name,
      abilities: form.abilities,
    }
    if (form.expires_at) payload.expires_at = form.expires_at
    const resp = await post<any>(`/admin/tenants/${props.tenantId}/api-tokens`, payload)
    newTokenValue.value = resp.data?.token
    copied.value = false
    createOpen.value = false
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao gerar token')
  } finally {
    saving.value = false
  }
}

async function revoke(t: any) {
  if (!confirm(`Revogar o token '${t.name}' do usuário ${t.user?.email}?`)) return
  try {
    await del(`/admin/tenants/${props.tenantId}/api-tokens/${t.id}`)
    toast.success('Token revogado')
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao revogar')
  }
}

function copyToken() {
  if (!newTokenValue.value) return
  navigator.clipboard.writeText(newTokenValue.value)
  copied.value = true
}

function closeTokenModal() {
  newTokenValue.value = null
  copied.value = false
}

function formatDate(s?: string | null) {
  if (!s) return ''
  return new Date(s).toLocaleString('pt-BR')
}

onMounted(load)
</script>
