<template>
  <div class="bc-page-enter">
    <!-- Header row -->
    <div class="d-flex align-items-start justify-content-between mb-4">
      <div>
        <span class="sc-breadcrumb-badge">NOVA CAMPANHA</span>
        <h1 class="sc-title">Selecione o Canal</h1>
        <span class="sc-subtitle">Escolha o canal de comunicação para sua campanha</span>
      </div>
      <div class="sc-credit-badge">
        <i class="ti ti-wallet" style="color:var(--bc-warning);font-size:1rem"></i>
        <span>Saldo disponível</span>
      </div>
    </div>

    <div v-if="loading" class="text-center py-5">
      <span class="spinner-border spinner-border-sm" style="color:var(--bc-primary)"></span>
    </div>

    <!-- 2x2 channel grid -->
    <div v-else class="sc-grid">
      <template v-for="ch in channels" :key="ch.id">
        <!-- Enabled channel -->
        <div v-if="ch.status === 'enabled'"
             class="sc-card sc-card--enabled"
             tabindex="0"
             role="button"
             @click="selectChannel(ch.id)"
             @keydown.enter="selectChannel(ch.id)">
          <div class="sc-card__icon" :style="{ background: ch.bgSolid }">
            <i :class="`ti ${ch.icon}`" :style="{ color: ch.color, fontSize: '1.5rem' }"></i>
          </div>
          <h3 class="sc-card__name">{{ ch.label }}</h3>
          <p class="sc-card__desc">{{ ch.desc }}</p>
          <span class="sc-badge sc-badge--active">
            <span class="sc-badge__dot sc-badge__dot--green"></span>
            CANAL ATIVO
          </span>
        </div>

        <!-- Pending setup -->
        <div v-else-if="ch.status === 'pending_setup'"
             class="sc-card sc-card--pending"
             aria-disabled="true"
             tabindex="-1">
          <div class="sc-card__icon sc-card__icon--muted" :style="{ background: ch.bgSolid }">
            <i :class="`ti ${ch.icon}`" :style="{ color: ch.color, fontSize: '1.5rem' }"></i>
          </div>
          <h3 class="sc-card__name sc-card__name--muted">{{ ch.label }}</h3>
          <p class="sc-card__desc sc-card__desc--muted">{{ ch.desc }}</p>
          <span class="sc-badge sc-badge--pending">
            <span class="sc-badge__dot sc-badge__dot--orange"></span>
            Configuração Necessária
          </span>
          <div class="sc-card__upgrade">
            <button v-if="configUrl(ch.id)" class="btn btn-sm btn-outline-primary sc-card__upgrade-btn" @click.stop="$router.push(configUrl(ch.id)!)">
              <i class="ti ti-settings me-1"></i> Configurar
            </button>
            <span v-else class="sc-card__upgrade-label">
              <i class="ti ti-info-circle me-1"></i>
              Configuração disponível com o administrador.
            </span>
          </div>
        </div>

        <!-- Disabled / locked -->
        <div v-else
             class="sc-card sc-card--locked"
             aria-disabled="true"
             tabindex="-1">
          <div class="sc-card__lock-icon"><i class="ti ti-lock" style="font-size:.7rem"></i></div>
          <div class="sc-card__icon sc-card__icon--muted" :style="{ background: ch.bgSolid }">
            <i :class="`ti ${ch.icon}`" :style="{ color: ch.color, fontSize: '1.5rem' }"></i>
          </div>
          <h3 class="sc-card__name sc-card__name--muted">{{ ch.label }}</h3>
          <p class="sc-card__desc sc-card__desc--muted">{{ ch.desc }}</p>
          <span class="sc-badge sc-badge--gray">
            Pendente
          </span>
          <div class="sc-card__upgrade">
            <span class="sc-card__upgrade-label">Disponível no plano Starter+</span>
            <button class="btn btn-sm btn-primary sc-card__upgrade-btn" @click.stop="$router.push('/settings/plans')">
              Upgrade
            </button>
          </div>
        </div>
      </template>
    </div>

    <!-- Footer -->
    <div class="sc-footer">
      <div class="sc-footer__info">
        <i class="ti ti-info-circle me-1" style="color:var(--bc-primary)"></i>
        <span>Cada canal tem custos e limites diferentes. Consulte a documentação para detalhes.</span>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-ghost-secondary" @click="$router.push('/campaigns')">
          Cancelar
        </button>
        <button class="btn btn-primary" :disabled="!selectedChannel" @click="proceedNext">
          Próximo Passo <i class="ti ti-arrow-right ms-1"></i>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'

type Channel = 'sms' | 'voice' | 'email' | 'whatsapp'

interface ChannelItem {
  id: Channel
  label: string
  desc: string
  icon: string
  bg: string
  bgSolid: string
  color: string
  status: string
}

const router = useRouter()
const { get } = useApi()
const loading = ref(true)
const channels = ref<ChannelItem[]>([])
const selectedChannel = ref<Channel | null>(null)

const defs: Record<string, Omit<ChannelItem, 'status'>> = {
  sms: { id: 'sms', label: 'SMS', desc: 'Mensagens de texto para celulares. Ideal para promoções curtas e lembretes.', icon: 'ti-message', bg: 'rgba(0,100,255,0.08)', bgSolid: 'rgba(0,100,255,0.12)', color: '#0064ff' },
  voice: { id: 'voice', label: 'Torpedo de Voz', desc: 'Áudio gerado por IA. Perfeito para cobranças, promoções e avisos.', icon: 'ti-phone', bg: 'rgba(234,179,8,0.08)', bgSolid: 'rgba(234,179,8,0.12)', color: '#d97706' },
  whatsapp: { id: 'whatsapp', label: 'WhatsApp', desc: 'Templates aprovados pela Meta. O canal mais usado no Brasil.', icon: 'ti-brand-whatsapp', bg: 'rgba(37,211,102,0.08)', bgSolid: 'rgba(37,211,102,0.12)', color: '#25d366' },
  email: { id: 'email', label: 'Email', desc: 'Campanhas com HTML personalizado. Ideal para newsletters e ofertas.', icon: 'ti-mail', bg: 'rgba(59,130,246,0.08)', bgSolid: 'rgba(59,130,246,0.12)', color: '#3b82f6' },
}

async function loadChannels() {
  loading.value = true
  try {
    const res = await get<any>('/channels')
    const data = res?.data ?? res ?? {}
    const order: Channel[] = ['sms', 'voice', 'whatsapp', 'email']
    channels.value = order.map(ch => ({
      ...defs[ch],
      status: data[ch]?.status ?? 'disabled',
    }))
  } catch {
    // Fallback: all enabled (superadmin)
    channels.value = Object.values(defs).map(d => ({ ...d, status: 'enabled' }))
  } finally {
    loading.value = false
  }
}

function configUrl(channel: string): string | null {
  // Só rotas que de fato existem hoje no router. Para canais sem página dedicada,
  // retornamos null e o template mostra mensagem em vez de levar a 404.
  const routes: Record<string, string> = {
    whatsapp: '/settings/whatsapp',
    email:    '/settings/email-domains',
  }
  return routes[channel] ?? null
}

function selectChannel(ch: Channel) {
  selectedChannel.value = ch
  router.push({ path: '/campaigns/create', query: { channel: ch } })
}

function proceedNext() {
  if (selectedChannel.value) {
    router.push({ path: '/campaigns/create', query: { channel: selectedChannel.value } })
  }
}

onMounted(loadChannels)
</script>

<style scoped>
/* ─── Breadcrumb badge ─── */
.sc-breadcrumb-badge {
  display: inline-block;
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--bc-primary);
  background: var(--bc-primary-subtle);
  padding: 0.25em 0.7em;
  border-radius: 9999px;
  margin-bottom: 0.6rem;
}

/* ─── Title ─── */
.sc-title {
  font-family: 'Manrope', sans-serif;
  font-size: 1.65rem;
  font-weight: 800;
  letter-spacing: -0.03em;
  color: var(--bc-text);
  margin: 0 0 0.25rem 0;
}
.sc-subtitle {
  font-size: 0.85rem;
  color: var(--bc-text-muted);
}

/* ─── Credit badge ─── */
.sc-credit-badge {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  background: var(--bc-glass-bg);
  backdrop-filter: var(--bc-glass-blur);
  padding: 0.5rem 1rem;
  border-radius: var(--bc-radius-lg);
  font-size: 0.82rem;
  color: var(--bc-text-muted);
}

/* ─── Responsive channel grid (até 4 colunas em desktop) ─── */
.sc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1rem;
  width: 100%;
}
/* Em telas >= 1200px com 4 canais, força 4 colunas equilibradas */
@media (min-width: 1200px) {
  .sc-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}
@media (max-width: 640px) {
  .sc-grid { grid-template-columns: 1fr; }
}

/* ─── Card base ─── */
.sc-card {
  position: relative;
  background: var(--bc-gray);
  border-radius: var(--bc-radius-lg);
  padding: 28px 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  transition: var(--bc-transition);
  min-height: 220px;
  height: 100%;
}

/* Enabled hover */
.sc-card--enabled {
  cursor: pointer;
}
.sc-card--enabled:hover {
  box-shadow: 0 0 24px rgba(0, 100, 255, 0.12), 0 8px 32px rgba(0, 0, 0, 0.2);
  transform: translateY(-4px);
}
.sc-card--enabled:focus-visible {
  outline: 2px solid var(--bc-primary);
  outline-offset: 2px;
}

/* Pending — atenua o conteúdo sem usar opacity no card (multiplica e some) */
.sc-card--pending {
  cursor: default;
  background: var(--bc-gray);
  filter: saturate(0.7);
}

/* Locked — idem; texto fica legível, badge/lock indicam estado */
.sc-card--locked {
  cursor: default;
  background: var(--bc-gray);
  filter: saturate(0.5);
}

/* Versões "muted" dos elementos do card, sem multiplicar opacity */
.sc-card__icon--muted { opacity: 0.6; }
.sc-card__name--muted { color: var(--bc-text-muted); }
.sc-card__desc--muted { color: var(--bc-text-muted); opacity: 0.75; }

/* Bloco de Upgrade / Configurar — empilhado e centralizado dentro do card */
.sc-card__upgrade {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
  width: 100%;
}
.sc-card__upgrade-label {
  font-size: 0.72rem;
  color: var(--bc-text-muted);
  text-align: center;
  line-height: 1.4;
}
.sc-card__upgrade-btn {
  border-radius: 8px;
  font-size: 0.78rem;
  padding: 0.3rem 0.85rem;
}

/* ─── Icon square ─── */
.sc-card__icon {
  width: 56px;
  height: 56px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 14px;
}

/* ─── Card text ─── */
.sc-card__name {
  font-family: 'Manrope', sans-serif;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--bc-text);
  margin: 0 0 6px 0;
}
.sc-card__desc {
  font-size: 0.82rem;
  color: var(--bc-text-muted);
  line-height: 1.5;
  margin: 0 0 14px 0;
}

/* ─── Status badges ─── */
.sc-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 0.3em 0.85em;
  border-radius: 9999px;
}
.sc-badge--active {
  background: rgba(16, 185, 129, 0.1);
  color: var(--bc-success);
}
.sc-badge--pending {
  background: rgba(245, 158, 11, 0.1);
  color: var(--bc-warning);
}
.sc-badge--gray {
  background: rgba(140, 144, 162, 0.1);
  color: var(--bc-text-muted);
}
.sc-badge__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}
.sc-badge__dot--green { background: var(--bc-success); }
.sc-badge__dot--orange { background: var(--bc-warning); }

/* ─── Lock icon ─── */
.sc-card__lock-icon {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.04);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--bc-text-muted);
  opacity: 0.4;
}

/* ─── Footer ─── */
.sc-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 2rem;
  width: 100%;
}
.sc-footer__info {
  font-size: 0.8rem;
  color: var(--bc-text-muted);
  display: flex;
  align-items: center;
}
@media (max-width: 640px) {
  .sc-footer {
    flex-direction: column;
    gap: 1rem;
    align-items: stretch;
  }
}
</style>
