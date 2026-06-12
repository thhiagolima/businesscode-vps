<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Domínios de Email</h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">
          Autentique seus domínios (SPF, DKIM, return-path) para enviar emails como <code>voce@seu-dominio.com</code> sem cair em spam.
        </span>
      </div>
      <button class="btn btn-primary" @click="openCreateModal">
        <i class="ti ti-plus me-1"></i>
        Adicionar domínio
      </button>
    </div>

    <!-- Fluxo passo-a-passo -->
    <div class="card mb-4" style="border-radius:14px;border-left:3px solid var(--bc-primary,#0064ff)">
      <div class="card-header" style="background:transparent">
        <h3 class="card-title d-flex align-items-center" style="margin:0">
          <i class="ti ti-route me-2" style="color:var(--bc-primary,#0064ff)"></i>
          Como funciona — fluxo em 7 passos
        </h3>
        <button class="btn btn-sm btn-ghost-secondary ms-auto" @click="showOnboarding = !showOnboarding">
          <i :class="showOnboarding ? 'ti ti-chevron-up' : 'ti ti-chevron-down'"></i>
          {{ showOnboarding ? 'Recolher' : 'Expandir' }}
        </button>
      </div>
      <div v-if="showOnboarding" class="card-body">
        <ol style="margin:0;padding-left:1.2rem;line-height:1.7">
          <li>
            <strong>Adicione seu domínio</strong> — clique em <em>"Adicionar domínio"</em> no topo direito e
            digite o domínio que você quer usar (ex: <code>marketing.empresa.com.br</code>).
            <div style="color:var(--bc-text-muted);font-size:0.82rem;margin-top:2px">
              Tracking de abertura/clique é opcional — deixamos OFF por padrão (LGPD).
            </div>
          </li>
          <li class="mt-2">
            <strong>Copie os 5 registros DNS</strong> que aparecem no drawer logo após criar o domínio.
            São <code>TXT × 4</code> (SPF + 3 DKIM) e <code>CNAME × 1</code> (return-path).
            Cada linha tem botão <em>Copiar</em>.
          </li>
          <li class="mt-2">
            <strong>Cole os registros no seu provedor de DNS</strong> — Cloudflare, Registro.br, GoDaddy, AWS Route53, etc.
            Procure por <em>"DNS Management"</em>, <em>"Zona DNS"</em> ou similar.
            Para cada registro: cole o <em>nome</em>, <em>tipo</em> e <em>valor</em> exatamente como aparece aqui.
          </li>
          <li class="mt-2">
            <strong>Aguarde a propagação</strong> — entre <strong>10 minutos e 24 horas</strong> dependendo do
            provedor. Pode fechar essa página e voltar depois.
          </li>
          <li class="mt-2">
            <strong>Clique em "Verificar agora"</strong> na linha do domínio. A gente consulta a Infobip que
            checa SPF, DKIM e return-path. Cada registro vira ✅ ou ❌ individualmente.
            <div style="color:var(--bc-text-muted);font-size:0.82rem;margin-top:2px">
              Também rodamos verificação automática diária às 05:00 (horário SP).
            </div>
          </li>
          <li class="mt-2">
            <strong>Quando o status virar <span class="badge bg-green-lt">ativo</span></strong>, seu domínio está pronto.
            A partir desse momento você pode enviar emails via API com
            <code>"from": "qualquer-coisa@seu-dominio.com"</code>.
          </li>
          <li class="mt-2">
            <strong>Dispare seu primeiro email</strong> via <code>POST /api/v1/messaging/email</code> com seu
            domínio autenticado no campo <code>from</code>.
            Acompanhe a entrega em
            <router-link to="/settings/webhooks" style="color:var(--bc-primary,#0064ff)">Webhooks</router-link>
            assinando os eventos <code>message.delivered</code> e <code>message.failed</code>.
          </li>
        </ol>

        <div class="alert alert-warning mt-3 mb-0" style="font-size:0.82rem">
          <i class="ti ti-alert-triangle me-1"></i>
          <strong>Atenção:</strong> sem domínio autenticado, emails enviados com <code>from</code> personalizado
          são <strong>rejeitados</strong> pelo sistema. Você pode continuar enviando sem <code>from</code> —
          nesse caso usamos o remetente padrão da plataforma, mas com taxa de entrega menor.
        </div>

        <div class="alert alert-info mt-2 mb-0" style="font-size:0.82rem">
          <i class="ti ti-bulb me-1"></i>
          <strong>Dica DMARC:</strong> depois que SPF e DKIM estiverem verdes por 7 dias, considere adicionar um
          registro DMARC ao seu DNS pra fortalecer a entrega:
          <code>v=DMARC1; p=none; rua=mailto:dmarc@seu-dominio.com</code> (modo monitoramento, não bloqueia nada).
          Esse passo é opcional — não exigimos.
        </div>
      </div>
    </div>

    <div class="card" style="border-radius:14px">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title">Meus domínios</h3>
        <button class="btn btn-sm btn-ghost-secondary" @click="loadDomains" :disabled="loading">
          <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>
          Atualizar
        </button>
      </div>

      <div v-if="loading && domains.length === 0" class="card-body text-center py-5">
        <span class="spinner-border spinner-border-sm"></span>
      </div>

      <div v-else-if="domains.length === 0" class="card-body text-center py-5">
        <i class="ti ti-mail-off" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">Nenhum dominio cadastrado</h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">
          Adicione seu primeiro dominio para enviar emails com sua propria identidade.
        </p>
      </div>

      <div v-else class="table-responsive">
        <table class="table card-table table-vcenter">
          <thead>
            <tr>
              <th>Dominio</th>
              <th>Status</th>
              <th>Tracking</th>
              <th>Criado em</th>
              <th class="text-end">Acoes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in domains" :key="d.id">
              <td>
                <strong style="font-family:'JetBrains Mono',monospace">{{ d.domain }}</strong>
              </td>
              <td>
                <span :class="`badge ${statusBadgeClass(d.status)}`">
                  <i :class="`ti ${statusIcon(d.status)} me-1`"></i>
                  {{ statusLabel(d.status) }}
                </span>
                <div style="font-size:0.74rem;color:var(--bc-text-muted);margin-top:2px">
                  {{ statusHint(d) }}
                </div>
              </td>
              <td style="font-size:0.78rem">
                <span class="badge bg-blue-lt me-1" v-if="d.tracking_opens">opens</span>
                <span class="badge bg-blue-lt" v-if="d.tracking_clicks">clicks</span>
                <span v-if="!d.tracking_opens && !d.tracking_clicks" class="text-muted">off</span>
              </td>
              <td style="font-size:0.82rem">{{ formatDate(d.created_at) }}</td>
              <td class="text-end">
                <button class="btn btn-sm btn-ghost-primary me-1" @click="openDnsModal(d)">
                  <i class="ti ti-list-details me-1"></i>
                  Registros DNS
                </button>
                <button
                  class="btn btn-sm btn-ghost-success me-1"
                  @click="verifyDomain(d)"
                  :disabled="verifying === d.id || d.status === 'active'"
                >
                  <span v-if="verifying === d.id" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="ti ti-check me-1"></i>
                  Verificar
                </button>
                <button
                  class="btn btn-sm btn-outline-danger"
                  @click="askDelete(d)"
                  :disabled="deleting === d.id"
                >
                  <span v-if="deleting === d.id" class="spinner-border spinner-border-sm me-1"></span>
                  <i v-else class="ti ti-trash me-1"></i>
                  Deletar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create modal -->
    <div
      class="modal modal-blur fade"
      :class="{ show: createOpen }"
      :style="{ display: createOpen ? 'block' : 'none' }"
      tabindex="-1"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">Adicionar dominio de envio</h3>
            <button type="button" class="btn-close" @click="closeCreateModal"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label required">Dominio</label>
              <input
                class="form-control"
                :class="{ 'is-invalid': createErrors.domain }"
                v-model="createForm.domain"
                placeholder="marketing.empresa.com.br"
                maxlength="253"
              />
              <div v-if="createErrors.domain" class="invalid-feedback">{{ createErrors.domain }}</div>
              <div v-else class="form-hint">
                Use um subdominio dedicado (ex: <code>marketing.empresa.com.br</code>) para
                preservar a reputacao do seu dominio principal.
              </div>
            </div>

            <div class="mb-2">
              <label class="form-check form-switch">
                <input type="checkbox" class="form-check-input" v-model="createForm.tracking_opens" />
                <span class="form-check-label">Rastrear aberturas (pixel)</span>
              </label>
              <div class="form-hint">
                <strong>Atencao LGPD:</strong> tracking de aberturas requer base legal e divulgacao na sua politica de privacidade.
              </div>
            </div>

            <div class="mb-2">
              <label class="form-check form-switch">
                <input type="checkbox" class="form-check-input" v-model="createForm.tracking_clicks" />
                <span class="form-check-label">Rastrear cliques (link redirect)</span>
              </label>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-link link-secondary" @click="closeCreateModal" :disabled="creating">Cancelar</button>
            <button class="btn btn-primary" @click="submitCreate" :disabled="creating">
              <span v-if="creating" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="ti ti-plus me-1"></i>
              Adicionar
            </button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="createOpen" class="modal-backdrop fade show"></div>

    <!-- DNS records modal -->
    <div
      class="modal modal-blur fade"
      :class="{ show: dnsOpen }"
      :style="{ display: dnsOpen ? 'block' : 'none' }"
      tabindex="-1"
    >
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">
              Registros DNS — <code>{{ selectedDomain?.domain }}</code>
            </h3>
            <button type="button" class="btn-close" @click="closeDnsModal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-info d-flex align-items-start">
              <i class="ti ti-info-circle me-2 mt-1"></i>
              <div>
                Adicione os registros abaixo no seu provedor de DNS (Cloudflare, Registro.br, GoDaddy, etc).
                Apos a propagacao (ate 24h), volte aqui e clique em <strong>Verificar</strong>.
                Para protecao adicional, configure tambem um registro DMARC.
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-vcenter">
                <thead>
                  <tr>
                    <th style="width:80px">Tipo</th>
                    <th>Nome / Host</th>
                    <th>Valor</th>
                    <th style="width:120px">Status</th>
                    <th class="text-end" style="width:90px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="rec in dnsRecords" :key="rec.label">
                    <td>
                      <span class="badge bg-secondary-lt">{{ rec.type }}</span>
                    </td>
                    <td style="font-family:'JetBrains Mono',monospace;font-size:0.78rem;word-break:break-all">
                      <div style="color:var(--bc-text-muted);font-size:0.7rem">{{ rec.label }}</div>
                      {{ rec.name }}
                    </td>
                    <td style="font-family:'JetBrains Mono',monospace;font-size:0.74rem;word-break:break-all;max-width:380px">
                      {{ rec.value || '—' }}
                    </td>
                    <td>
                      <span v-if="rec.verified" class="badge bg-success-lt">
                        <i class="ti ti-check me-1"></i>Verificado
                      </span>
                      <span v-else class="badge bg-secondary-lt">
                        <i class="ti ti-clock me-1"></i>Pendente
                      </span>
                    </td>
                    <td class="text-end">
                      <button
                        class="btn btn-sm btn-ghost-secondary"
                        @click="copy(rec.value)"
                        :disabled="!rec.value"
                      >
                        <i class="ti ti-copy"></i>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="selectedDomain?.last_verification_error" class="alert alert-danger mt-3">
              <strong>Ultimo erro:</strong> {{ selectedDomain.last_verification_error }}
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-link link-secondary" @click="closeDnsModal">Fechar</button>
            <button
              class="btn btn-primary"
              @click="verifyDomain(selectedDomain!)"
              :disabled="verifying === selectedDomain?.id || selectedDomain?.status === 'active'"
            >
              <span v-if="verifying === selectedDomain?.id" class="spinner-border spinner-border-sm me-2"></span>
              <i v-else class="ti ti-refresh me-1"></i>
              Verificar agora
            </button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="dnsOpen" class="modal-backdrop fade show"></div>

    <!-- Confirm delete -->
    <ConfirmModal
      :visible="!!domainToDelete"
      title="Deletar dominio?"
      :message="`O dominio '${domainToDelete?.domain ?? ''}' sera removido do Infobip e voce nao podera mais enviar emails 'from' usando ele ate cadastrar novamente.`"
      confirm-text="Deletar"
      confirm-class="btn-danger"
      :loading="deleting !== null"
      @confirm="confirmDelete"
      @cancel="domainToDelete = null"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ConfirmModal from '@/components/ui/ConfirmModal.vue'

type EmailDomain = {
  id: number
  domain: string
  status: 'pending' | 'verifying' | 'active' | 'failed'
  dkim_selector: string | null
  dkim_value: string | null
  spf_value: string | null
  return_path_value: string | null
  dkim_verified: boolean
  spf_verified: boolean
  return_path_verified: boolean
  tracking_opens: boolean
  tracking_clicks: boolean
  created_at: string
  last_verified_at: string | null
  last_verification_error: string | null
  verification_attempts: number
}

const { get, post, del } = useApi()
const toast = useToast()

const domains = ref<EmailDomain[]>([])
const loading = ref(false)
const verifying = ref<number | null>(null)
const deleting = ref<number | null>(null)

// Onboarding panel — collapsed by default once the tenant has at least 1 domain
const showOnboarding = ref(true)

const createOpen = ref(false)
const creating = ref(false)
const createForm = ref<{ domain: string; tracking_opens: boolean; tracking_clicks: boolean }>({
  domain: '',
  tracking_opens: false,
  tracking_clicks: false,
})
const createErrors = ref<Record<string, string>>({})

const dnsOpen = ref(false)
const selectedDomain = ref<EmailDomain | null>(null)
const domainToDelete = ref<EmailDomain | null>(null)

const dnsRecords = computed(() => {
  const d = selectedDomain.value
  if (!d) return []
  return [
    {
      label: 'SPF',
      type: 'TXT',
      name: d.domain,
      value: d.spf_value,
      verified: d.spf_verified,
    },
    {
      label: 'DKIM',
      type: 'TXT',
      name: d.dkim_selector ? `${d.dkim_selector}._domainkey.${d.domain}` : `_domainkey.${d.domain}`,
      value: d.dkim_value,
      verified: d.dkim_verified,
    },
    {
      label: 'Return-Path',
      type: 'CNAME',
      name: `bounces.${d.domain}`,
      value: d.return_path_value,
      verified: d.return_path_verified,
    },
  ]
})

function statusLabel(s: string): string {
  switch (s) {
    case 'pending': return 'Pendente'
    case 'verifying': return 'Verificando'
    case 'active': return 'Ativo'
    case 'failed': return 'Falhou'
    default: return s
  }
}

function statusBadgeClass(s: string): string {
  switch (s) {
    case 'active': return 'bg-success-lt'
    case 'verifying': return 'bg-orange-lt'
    case 'failed': return 'bg-danger-lt'
    default: return 'bg-secondary-lt'
  }
}

function statusIcon(s: string): string {
  switch (s) {
    case 'active': return 'ti-check'
    case 'verifying': return 'ti-loader'
    case 'failed': return 'ti-x'
    default: return 'ti-clock'
  }
}

function statusHint(d: EmailDomain): string {
  if (d.status === 'pending') {
    return 'Aguardando voce adicionar os registros DNS'
  }
  if (d.status === 'verifying') {
    const verified = [d.dkim_verified, d.spf_verified, d.return_path_verified].filter(Boolean).length
    return `${verified} de 3 registros verificados. Aguardando os restantes.`
  }
  if (d.status === 'active') {
    return `Pronto! Voce pode enviar emails como user@${d.domain}`
  }
  if (d.status === 'failed') {
    return 'Verificacao falhou apos 30 tentativas. Confira os registros DNS.'
  }
  return ''
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  return d.toLocaleString('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

async function loadDomains() {
  loading.value = true
  try {
    const res = await get<any>('/email-domains')
    const list: any[] = Array.isArray(res) ? res : (res?.data ?? [])
    domains.value = list as EmailDomain[]
    // Collapse onboarding once tenant has at least one domain configured
    if (domains.value.length > 0) {
      showOnboarding.value = false
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao carregar dominios')
  } finally {
    loading.value = false
  }
}

function openCreateModal() {
  createForm.value = { domain: '', tracking_opens: false, tracking_clicks: false }
  createErrors.value = {}
  createOpen.value = true
}
function closeCreateModal() {
  createOpen.value = false
}

async function submitCreate() {
  createErrors.value = {}
  creating.value = true
  try {
    const res = await post<any>('/email-domains', {
      domain: createForm.value.domain.trim().toLowerCase(),
      tracking_opens: createForm.value.tracking_opens,
      tracking_clicks: createForm.value.tracking_clicks,
    })
    toast.success('Dominio cadastrado. Agora adicione os registros DNS.')
    createOpen.value = false
    await loadDomains()
    // Auto-open the DNS modal for the new domain
    const newDomain = res?.data
    if (newDomain) {
      selectedDomain.value = newDomain
      dnsOpen.value = true
    }
  } catch (e: any) {
    const data = e?.response?.data
    if (data?.errors) {
      const flat: Record<string, string> = {}
      for (const k of Object.keys(data.errors)) {
        flat[k] = Array.isArray(data.errors[k]) ? data.errors[k][0] : String(data.errors[k])
      }
      createErrors.value = flat
    }
    toast.error(data?.message ?? e?.message ?? 'Erro ao cadastrar dominio')
  } finally {
    creating.value = false
  }
}

function openDnsModal(d: EmailDomain) {
  selectedDomain.value = d
  dnsOpen.value = true
}
function closeDnsModal() {
  dnsOpen.value = false
  selectedDomain.value = null
}

async function verifyDomain(d: EmailDomain) {
  if (!d) return
  verifying.value = d.id
  try {
    const res = await post<any>(`/email-domains/${d.id}/verify`, {})
    const updated = res?.data as EmailDomain | undefined
    if (updated) {
      // Replace in list
      const idx = domains.value.findIndex(x => x.id === updated.id)
      if (idx >= 0) domains.value[idx] = updated
      if (selectedDomain.value?.id === updated.id) selectedDomain.value = updated
      if (updated.status === 'active') {
        toast.success(`Dominio ${updated.domain} verificado com sucesso!`)
      } else {
        const verified = [updated.dkim_verified, updated.spf_verified, updated.return_path_verified].filter(Boolean).length
        toast.info(`${verified} de 3 registros verificados. Aguarde propagacao DNS.`)
      }
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Falha ao verificar dominio')
  } finally {
    verifying.value = null
  }
}

function askDelete(d: EmailDomain) {
  domainToDelete.value = d
}

async function confirmDelete() {
  if (!domainToDelete.value) return
  const d = domainToDelete.value
  deleting.value = d.id
  try {
    await del(`/email-domains/${d.id}`)
    toast.success(`Dominio ${d.domain} removido`)
    domainToDelete.value = null
    await loadDomains()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao remover dominio')
  } finally {
    deleting.value = null
  }
}

async function copy(text: string | null) {
  if (!text) return
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback
      const ta = document.createElement('textarea')
      ta.value = text
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
    }
    toast.success('Copiado')
  } catch {
    toast.error('Nao foi possivel copiar')
  }
}

onMounted(() => {
  loadDomains()
})
</script>

<style scoped>
.form-check.form-switch {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.4rem;
}
</style>
