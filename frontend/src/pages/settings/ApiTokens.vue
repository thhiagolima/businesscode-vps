<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Tokens de API</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">
          Crie tokens para integrar SMS, voz e email via API server-to-server.
        </span>
      </div>
    </div>

    <div class="row g-4">
      <!-- Formulário de criação -->
      <div class="col-lg-5">
        <div class="card" style="border-radius:14px">
          <div class="card-header"><h3 class="card-title">Gerar novo token</h3></div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label required">Nome</label>
              <input
                class="form-control"
                :class="{ 'is-invalid': formErrors.name }"
                v-model="form.name"
                placeholder="Ex: Integração ERP"
                maxlength="120"
                required />
              <div v-if="formErrors.name" class="invalid-feedback">{{ formErrors.name }}</div>
              <div v-else class="form-hint">Use um nome descritivo para identificar onde o token é usado.</div>
            </div>

            <div class="mb-3">
              <label class="form-label required">Permissões (abilities)</label>
              <div :class="{ 'is-invalid': formErrors.abilities }">
                <label
                  v-for="opt in abilityOptions"
                  :key="opt.value"
                  class="form-check"
                >
                  <input
                    class="form-check-input"
                    type="checkbox"
                    :value="opt.value"
                    v-model="form.abilities"
                  />
                  <span class="form-check-label">
                    <code class="me-2">{{ opt.value }}</code>
                    <span class="text-muted" style="font-size:0.78rem">{{ opt.label }}</span>
                  </span>
                </label>
              </div>
              <div v-if="formErrors.abilities" class="invalid-feedback d-block">{{ formErrors.abilities }}</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Expira em (opcional)</label>
              <input
                type="datetime-local"
                class="form-control"
                v-model="form.expires_at"
              />
              <div class="form-hint">Deixe em branco para nunca expirar.</div>
            </div>
          </div>
          <div class="card-footer d-flex justify-content-end">
            <button class="btn btn-primary" @click="createToken" :disabled="creating">
              <span v-if="creating" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="ti ti-key me-1"></i>
              Gerar token
            </button>
          </div>
        </div>

        <!-- Quickstart docs -->
        <div class="card mt-4" style="border-radius:14px">
          <div class="card-header">
            <h3 class="card-title">
              <i class="ti ti-terminal-2 me-1"></i>
              Quickstart (curl)
            </h3>
          </div>
          <div class="card-body">
            <p class="text-muted" style="font-size:0.82rem">
              Use o token gerado no header <code>Authorization: Bearer YOUR_TOKEN</code>.
              <code>Idempotency-Key</code> é obrigatório (UUID) para evitar envios duplicados.
              <strong>Atenção:</strong> todos os endpoints da API usam o prefixo <code>/api/v1/...</code>.
            </p>

            <div v-for="snippet in curlSnippets" :key="snippet.title" class="mb-3">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <strong style="font-size:0.85rem">{{ snippet.title }}</strong>
                <button class="btn btn-sm btn-ghost-secondary" @click="copy(snippet.body)">
                  <i class="ti ti-copy me-1"></i>Copiar
                </button>
              </div>
              <pre style="background:var(--bc-surface-container,#1a1e37);color:#dee0ff;padding:0.75rem;border-radius:8px;font-size:0.74rem;overflow-x:auto;margin:0"><code>{{ snippet.body }}</code></pre>
            </div>
          </div>
        </div>
      </div>

      <!-- Lista de tokens -->
      <div class="col-lg-7">
        <div class="card" style="border-radius:14px">
          <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title">Tokens existentes</h3>
            <button class="btn btn-sm btn-ghost-secondary" @click="loadTokens" :disabled="loading">
              <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
              <i v-else class="ti ti-refresh me-1"></i>
              Atualizar
            </button>
          </div>

          <div v-if="loading && tokens.length === 0" class="card-body text-center py-5">
            <span class="spinner-border spinner-border-sm"></span>
          </div>

          <div v-else-if="tokens.length === 0" class="card-body text-center py-5">
            <i class="ti ti-key-off" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
            <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">Nenhum token criado</h3>
            <p style="font-size:0.85rem;color:var(--bc-text-muted)">
              Gere seu primeiro token ao lado para começar a integrar via API.
            </p>
          </div>

          <div v-else class="table-responsive">
            <table class="table card-table table-vcenter">
              <thead>
                <tr>
                  <th>Nome</th>
                  <th>Permissões</th>
                  <th>Criado em</th>
                  <th>Expira em</th>
                  <th>Último uso</th>
                  <th class="text-end">Ações</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in tokens" :key="t.id">
                  <td>
                    <span style="font-weight:600">{{ t.name }}</span>
                  </td>
                  <td>
                    <span
                      v-for="a in t.abilities"
                      :key="a"
                      class="badge bg-blue-lt me-1 mb-1"
                      style="font-family:'JetBrains Mono',monospace;font-size:0.68rem"
                    >{{ a }}</span>
                  </td>
                  <td style="font-size:0.82rem">{{ formatDate(t.created_at) }}</td>
                  <td style="font-size:0.82rem">
                    <span v-if="!t.expires_at" class="text-muted">—</span>
                    <span v-else :class="{ 'text-danger': isExpired(t.expires_at) }">
                      {{ formatDate(t.expires_at) }}
                    </span>
                  </td>
                  <td style="font-size:0.82rem">
                    <span v-if="t.last_used_at">{{ formatDate(t.last_used_at) }}</span>
                    <span v-else class="text-muted">Nunca</span>
                  </td>
                  <td class="text-end">
                    <button
                      class="btn btn-sm btn-outline-danger"
                      @click="askRevoke(t)"
                      :disabled="revoking === t.id"
                    >
                      <span v-if="revoking === t.id" class="spinner-border spinner-border-sm me-1"></span>
                      <i v-else class="ti ti-trash me-1"></i>
                      Revogar
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: plaintext token (mostrado somente após criar) -->
    <div
      class="modal modal-blur fade"
      :class="{ show: !!plaintextToken }"
      :style="{ display: plaintextToken ? 'block' : 'none' }"
      tabindex="-1"
    >
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">
              <i class="ti ti-key text-warning me-1"></i>
              Token gerado
            </h3>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning d-flex align-items-start" role="alert">
              <i class="ti ti-alert-triangle me-2 mt-1"></i>
              <div>
                <strong>Este token só será exibido AGORA.</strong>
                Salve em local seguro (cofre de senhas ou variáveis de ambiente).
                Após fechar esta janela, não será mais possível visualizá-lo.
              </div>
            </div>

            <label class="form-label">Token (plaintext)</label>
            <div class="input-group">
              <input
                ref="tokenInputRef"
                type="text"
                class="form-control"
                style="font-family:'JetBrains Mono',monospace;font-size:0.82rem"
                :value="plaintextToken"
                readonly
              />
              <button class="btn btn-primary" @click="copy(plaintextToken || '')">
                <i class="ti ti-copy me-1"></i>
                Copiar
              </button>
            </div>
            <div v-if="createdMeta" class="mt-3" style="font-size:0.82rem;color:var(--bc-text-muted)">
              <div><strong>Permissões:</strong>
                <span v-for="a in createdMeta.abilities" :key="a" class="badge bg-blue-lt ms-1">{{ a }}</span>
              </div>
              <div v-if="createdMeta.expires_at" class="mt-1">
                <strong>Expira em:</strong> {{ formatDate(createdMeta.expires_at) }}
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-primary" @click="dismissPlaintext">
              Já salvei, fechar
            </button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="plaintextToken" class="modal-backdrop fade show"></div>

    <!-- Confirm revoke -->
    <ConfirmModal
      :visible="!!tokenToRevoke"
      title="Revogar token?"
      :message="`O token '${tokenToRevoke?.name ?? ''}' será permanentemente revogado. Integrações que o utilizam pararão de funcionar.`"
      confirm-text="Revogar"
      confirm-class="btn-danger"
      :loading="revoking !== null"
      @confirm="confirmRevoke"
      @cancel="tokenToRevoke = null"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

type ApiToken = {
  id: number
  name: string
  abilities: string[]
  created_at: string
  expires_at: string | null
  last_used_at: string | null
}

type CreatedTokenResp = {
  id: number
  token: string
  abilities: string[]
  expires_at: string | null
}

const { get, post, del } = useApi()
const toast = useToast()

const abilityOptions = [
  { value: 'messaging:sms', label: 'Enviar SMS' },
  { value: 'messaging:voice', label: 'Enviar chamadas de voz' },
  { value: 'messaging:email', label: 'Enviar email' },
  { value: 'messaging:read', label: 'Ler status / relatórios' },
  { value: 'messaging:*', label: 'Acesso total ao messaging (super)' },
]

const form = ref<{ name: string; abilities: string[]; expires_at: string }>({
  name: '',
  abilities: [],
  expires_at: '',
})
const formErrors = ref<Record<string, string>>({})

const tokens = ref<ApiToken[]>([])
const loading = ref(false)
const creating = ref(false)
const revoking = ref<number | null>(null)

const plaintextToken = ref<string | null>(null)
const createdMeta = ref<{ abilities: string[]; expires_at: string | null } | null>(null)
const tokenInputRef = ref<HTMLInputElement | null>(null)

const tokenToRevoke = ref<ApiToken | null>(null)

const curlSnippets = computed(() => [
  {
    title: 'SMS — POST /api/v1/messaging/sms',
    body: `# Use {chave} no "content" e passe os valores em "variables".
# Placeholders sem valor permanecem como estão (ex: {missing}).
curl -X POST https://${apiHost()}/api/v1/messaging/sms \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Idempotency-Key: $(uuidgen)" \\
  -H "Content-Type: application/json" \\
  -d '{
    "to": "+5521980194445",
    "content": "Olá {primeiro_nome}, faltam {dias_sem_login} dias sem entrar. Acesse: {link}",
    "variables": {
      "primeiro_nome": "Pedro",
      "dias_sem_login": 7,
      "link": "https://app.exemplo.com/login"
    }
  }'`,
  },
  {
    title: 'Voz (TTS) — POST /api/v1/messaging/voice',
    body: `# "variables" também substitui {chave} no "content" antes da síntese.
curl -X POST https://${apiHost()}/api/v1/messaging/voice \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Idempotency-Key: $(uuidgen)" \\
  -H "Content-Type: application/json" \\
  -d '{
    "to": "+5521980194445",
    "content": "Olá {primeiro_nome}, sua entrega chega em {dias} dias.",
    "variables": { "primeiro_nome": "Pedro", "dias": 2 }
  }'`,
  },
  {
    title: 'Voz (áudio MP3/WAV) — POST /api/v1/messaging/voice',
    body: `curl -X POST https://${apiHost()}/api/v1/messaging/voice \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Idempotency-Key: $(uuidgen)" \\
  -H "Content-Type: application/json" \\
  -d '{"to":"+5521980194445","audio_url":"https://meu-bucket.s3.amazonaws.com/audio.mp3"}'`,
  },
  {
    title: 'Email — POST /api/v1/messaging/email',
    body: `# "from" é o email remetente — o domínio (parte depois do @) precisa estar
# verificado em Configurações > Domínios de Email.
# "content" aceita HTML — <script>, <iframe>, onerror= e javascript: são removidos.
# "variables" substitui {chave} no "subject" e no "content".
curl -X POST https://${apiHost()}/api/v1/messaging/email \\
  -H "Authorization: Bearer YOUR_TOKEN" \\
  -H "Idempotency-Key: $(uuidgen)" \\
  -H "Content-Type: application/json" \\
  -d '{
    "to": "cliente@exemplo.com",
    "from": "pedidos@seu-dominio-verificado.com.br",
    "from_name": "Empresa Parceria",
    "reply_to": "suporte@seu-dominio-verificado.com.br",
    "subject": "Olá {primeiro_nome}, seu pedido {pedido} foi confirmado",
    "content": "<h1>Obrigado, {primeiro_nome}!</h1><p>Seu pedido <strong>#{pedido}</strong> foi confirmado. Total: <strong>{total}</strong>.</p><p><a href=\\"{link}\\">Acompanhar pedido</a></p>",
    "variables": {
      "primeiro_nome": "Pedro",
      "pedido": "ABC123",
      "total": "R$ 199,90",
      "link": "https://exemplo.com/pedido/ABC123"
    }
  }'`,
  },
])

function apiHost(): string {
  if (typeof window !== 'undefined' && window.location?.host) {
    return window.location.host
  }
  return 'seu-dominio.com'
}

function validateForm(): boolean {
  const errors: Record<string, string> = {}
  if (!form.value.name.trim()) {
    errors.name = 'Informe um nome para o token.'
  }
  if (!form.value.abilities.length) {
    errors.abilities = 'Selecione ao menos uma permissão.'
  }
  formErrors.value = errors
  return Object.keys(errors).length === 0
}

function toIso(local: string): string | undefined {
  if (!local) return undefined
  // datetime-local returns "YYYY-MM-DDTHH:mm" in local time
  const d = new Date(local)
  if (isNaN(d.getTime())) return undefined
  return d.toISOString()
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function isExpired(iso: string | null): boolean {
  if (!iso) return false
  return new Date(iso).getTime() < Date.now()
}

async function loadTokens() {
  loading.value = true
  try {
    const res = await get<any>('/auth/api-tokens')
    // backend may return data or wrapped resource
    const list: any[] = Array.isArray(res) ? res : (res?.tokens ?? res?.data ?? [])
    tokens.value = list.map(t => ({
      id: t.id,
      name: t.name,
      abilities: Array.isArray(t.abilities) ? t.abilities : [],
      created_at: t.created_at,
      expires_at: t.expires_at ?? null,
      last_used_at: t.last_used_at ?? null,
    }))
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao carregar tokens')
  } finally {
    loading.value = false
  }
}

async function createToken() {
  if (!validateForm()) return
  creating.value = true
  try {
    const payload: Record<string, unknown> = {
      name: form.value.name.trim(),
      abilities: form.value.abilities,
    }
    const iso = toIso(form.value.expires_at)
    if (iso) payload.expires_at = iso

    const res = await post<CreatedTokenResp>('/auth/api-tokens', payload)
    if (!res?.token) {
      throw new Error('Resposta inválida do servidor (token ausente).')
    }
    plaintextToken.value = res.token
    createdMeta.value = {
      abilities: res.abilities ?? form.value.abilities,
      expires_at: res.expires_at ?? null,
    }
    toast.success('Token gerado com sucesso')
    // Reset form
    form.value = { name: '', abilities: [], expires_at: '' }
    formErrors.value = {}
    await loadTokens()
  } catch (e: any) {
    const data = e?.response?.data
    if (data?.errors && typeof data.errors === 'object') {
      const flat: Record<string, string> = {}
      for (const k of Object.keys(data.errors)) {
        flat[k] = Array.isArray(data.errors[k]) ? data.errors[k][0] : String(data.errors[k])
      }
      formErrors.value = flat
    }
    toast.error(data?.message ?? e?.message ?? 'Erro ao gerar token')
  } finally {
    creating.value = false
  }
}

function dismissPlaintext() {
  plaintextToken.value = null
  createdMeta.value = null
}

function askRevoke(t: ApiToken) {
  tokenToRevoke.value = t
}

async function confirmRevoke() {
  if (!tokenToRevoke.value) return
  const t = tokenToRevoke.value
  revoking.value = t.id
  try {
    await del(`/auth/api-tokens/${t.id}`)
    toast.success(`Token '${t.name}' revogado`)
    tokenToRevoke.value = null
    await loadTokens()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao revogar token')
  } finally {
    revoking.value = null
  }
}

async function copy(text: string) {
  if (!text) return
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback
      const input = tokenInputRef.value
      if (input) {
        input.select()
        document.execCommand('copy')
      }
    }
    toast.success('Copiado para a área de transferência')
  } catch {
    toast.error('Não foi possível copiar')
  }
}

onMounted(() => {
  loadTokens()
})
</script>

<style scoped>
.form-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.4rem;
}
pre code {
  background: transparent;
  color: inherit;
  padding: 0;
}
</style>
