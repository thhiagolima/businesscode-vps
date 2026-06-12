<template>
  <div>
    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
      <div>
        <h2 style="font-family:'Manrope',sans-serif;font-weight:800;font-size:1.75rem;letter-spacing:-0.03em;margin:0">Visão Geral</h2>
        <p style="color:var(--bc-text-muted);font-size:0.85rem;margin:0.25rem 0 0">Desempenho estratégico em todos os canais ativos</p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <label class="visually-hidden" for="dashboard-period">Período</label>
        <select id="dashboard-period" class="form-select form-select-sm bc-select" v-model="period" @change="loadStats" aria-label="Filtrar período do dashboard">
          <option v-for="opt in periodOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
        <button class="btn btn-sm btn-icon bc-btn-refresh" @click="loadStats" :disabled="isLoading" title="Atualizar" aria-label="Atualizar dados do dashboard">
          <i class="ti ti-refresh" :class="{ 'bc-spin': isLoading }"></i>
        </button>
        <button class="btn btn-ghost-secondary btn-sm" @click="router.push('/reports/campaigns')">
          <i class="ti ti-download me-1"></i> Relatórios
        </button>
        <button class="btn btn-primary btn-sm" @click="router.push('/campaigns/new')">
          <i class="ti ti-plus me-1"></i> Nova Campanha
        </button>
      </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
      <template v-if="isLoading">
        <div class="col-md-4" v-for="i in 3" :key="i">
          <div class="card placeholder-glow" style="border-radius:14px">
            <div class="card-body">
              <div class="mb-2"><span class="placeholder col-5"></span></div>
              <div><span class="placeholder col-3" style="height:28px"></span></div>
            </div>
          </div>
        </div>
      </template>
      <template v-else-if="stats">
        <div class="col-md-4 bc-fade-in" style="animation-delay:0ms">
          <KpiCard label="Total de Campanhas" :value="stats.kpis.total_campaigns" icon="ti-speakerphone" color="primary" clickable @click="router.push('/campaigns')">
            <StatusBadge v-if="stats.kpis.active_campaigns > 0" :label="`${stats.kpis.active_campaigns} ativas`" color="primary" variant="light" dot />
          </KpiCard>
        </div>
        <div class="col-md-4 bc-fade-in" style="animation-delay:60ms">
          <KpiCard label="Ativas Agora" :value="stats.kpis.active_campaigns" icon="ti-broadcast" color="info">
            <template #subtitle>Campanhas em execução</template>
          </KpiCard>
        </div>
        <div class="col-md-4 bc-fade-in" style="animation-delay:120ms">
          <KpiCard label="Saldo (R$)" :value="balanceLabel" icon="ti-wallet" :color="balanceColor" clickable @click="router.push('/settings/saldo')">
            <template #subtitle>{{ balanceSubtitle }}</template>
          </KpiCard>
        </div>
      </template>
    </div>

    <!-- Charts -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-lg-8">
        <div class="card">
          <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">Desempenho de Campanhas</h3>
            <div class="d-flex gap-1">
              <button v-for="p in chartPeriods" :key="p.value" class="btn btn-sm" :class="chartPeriod === p.value ? 'btn-primary' : 'btn-ghost-secondary'" @click="chartPeriod = p.value" style="font-size:0.72rem;padding:0.2rem 0.6rem">{{ p.label }}</button>
            </div>
          </div>
          <div class="card-body">
            <apexchart type="area" height="240" :options="chartOptions" :series="chartSeries"></apexchart>
          </div>
        </div>
      </div>
      <div class="col-12 col-lg-4">
        <div class="card" style="height:100%">
          <div class="card-header"><h3 class="card-title mb-0">Canais com Melhor Desempenho</h3></div>
          <div class="card-body d-flex flex-column justify-content-center gap-3">
            <div v-if="channelPerformance.length === 0" class="text-center text-muted small py-4">
              <i class="ti ti-chart-bar-off d-block mb-2" style="font-size:1.5rem"></i>
              Nenhum disparo registrado ainda.
            </div>
            <div v-else v-for="(ch, i) in channelPerformance" :key="i">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <span style="font-size:0.82rem;font-weight:500;color:var(--bc-text)">{{ ch.name }}</span>
                <span style="font-size:0.82rem;font-weight:600;color:var(--bc-text)">{{ ch.percent }}%</span>
              </div>
              <div style="height:6px;background:var(--bc-gray-soft);border-radius:3px;overflow:hidden">
                <div :style="`width:${ch.percent}%;height:100%;background:${ch.color};border-radius:3px;transition:width 0.6s ease`"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Campaigns table — full width -->
    <div class="row g-3">
      <div class="col-12">
        <div class="card">
          <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">Campanhas Recentes</h3>
            <a href="#" class="btn btn-sm btn-ghost-secondary" @click.prevent="router.push('/campaigns')">
              Ver todas <i class="ti ti-arrow-right ms-1"></i>
            </a>
          </div>
          <div class="table-responsive">
            <table class="table table-vcenter card-table table-clickable">
              <thead>
                <tr><th>Campanha</th><th>Tipo</th><th>Status</th><th class="text-end">Engajamento</th><th class="text-end">Custo</th><th class="text-end">Data</th></tr>
              </thead>
              <tbody>
                <tr v-for="c in stats?.recent_campaigns ?? []" :key="c.id" @click="router.push(`/campaigns/${c.id}`)">
                  <td class="fw-medium">
                    <span class="d-inline-block rounded-circle me-2" :style="`width:8px;height:8px;background:${channelColor(c.type)}`"></span>
                    {{ c.name }}
                  </td>
                  <td>
                    <span class="d-inline-block rounded-circle me-1" :style="`width:8px;height:8px;background:${channelColor(c.type)}`"></span>
                    <StatusBadge :label="c.type.toUpperCase()" :status="c.type" />
                  </td>
                  <td><StatusBadge :label="statusLabel(c.status)" :status="c.status" dot /></td>
                  <td class="text-end">
                    <span v-if="c.estimated_contacts > 0" :style="{ color: Math.round((c.sent_count/c.estimated_contacts)*100) >= 80 ? '#10b981' : '#f59e0b' }">
                      {{ Math.round((c.sent_count / c.estimated_contacts) * 100) }}%
                    </span>
                    <span v-else style="color:var(--bc-text-muted)">--</span>
                  </td>
                  <td class="text-end text-mono" style="font-size:0.8rem">{{ c.sent_count?.toLocaleString('pt-BR') ?? 0 }}</td>
                  <td class="text-end" style="font-size:0.8rem;color:var(--bc-text-muted)">{{ formatDate(c.created_at ?? c.scheduled_at) }}</td>
                </tr>
                <tr v-if="!(stats?.recent_campaigns?.length)">
                  <td colspan="6" class="text-center py-4">
                    <div style="color:var(--bc-text-muted)">
                      <i class="ti ti-speakerphone" style="font-size:1.5rem;display:block;margin-bottom:0.5rem;opacity:0.3"></i>
                      <span style="font-size:0.85rem">Nenhuma campanha ainda</span>
                      <div class="mt-2">
                        <button class="btn btn-sm btn-primary" @click="router.push('/campaigns/new')">
                          <i class="ti ti-plus me-1"></i>Criar campanha
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useReportStore } from '@/stores/report'
import { useAuthStore } from '@/stores/auth'
import KpiCard from '@/components/ui/KpiCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { brl } from '@/utils/currency'

const router = useRouter()
const report = useReportStore()
const auth = useAuthStore()
const isLoading = computed(() => report.isLoading)
const stats = computed(() => report.dashboardStats)

const period = ref(30)
const periodOptions = [
  { label: '7 dias', value: 7 },
  { label: '14 dias', value: 14 },
  { label: '30 dias', value: 30 },
  { label: '90 dias', value: 90 },
]

// O endpoint /dashboard/stats retorna SEMPRE 14 dias de daily_sent.
// Para honrar a seleção do usuário sem mentir, oferecemos apenas as opções
// que conseguimos servir a partir dos dados reais (filtragem client-side).
const chartPeriod = ref(14)
const chartPeriods = [
  { label: '7d', value: 7 },
  { label: '14d', value: 14 },
]

const loadStats = () => report.fetchDashboard()

// Saldo (R$) — prefer tenant.balance_cents (cached on /auth/me), fall through to KPI from dashboard payload.
const balanceCents = computed<number>(() => {
  const t = auth.user?.tenant as any
  const fromTenant = t?.balance_cents
  if (typeof fromTenant === 'number') return fromTenant
  return stats.value?.kpis?.balance_cents ?? 0
})
const balanceLabel = computed<string>(() => (balanceCents.value === -1 ? '∞' : brl(balanceCents.value)))
const balanceColor = computed<'success' | 'warning' | 'danger'>(() => {
  if (balanceCents.value === -1) return 'success'
  if (balanceCents.value < 0) return 'danger'
  if (balanceCents.value < 5000) return 'warning' // < R$ 50
  return 'success'
})
const balanceSubtitle = computed<string>(() => {
  if (balanceCents.value === -1) return 'Ilimitado'
  if (balanceCents.value < 0) return 'Conta no negativo'
  return 'Saldo disponível'
})

watch(stats, (s) => {
  if (s?.kpis?.balance_cents !== undefined) auth.updateCredits(s.kpis.balance_cents)
})

onMounted(async () => {
  await auth.refreshUser()
  report.fetchDashboard()
})

const channelPerformance = computed(() => {
  const ch = stats.value?.by_channel ?? {}
  const total = (ch.sms ?? 0) + (ch.voice ?? 0) + (ch.email ?? 0) + (ch.whatsapp ?? 0)
  // Sem dispatches: NÃO renderizar 4 canais zerados (parecia base populada).
  // O template mostra um empty-state quando o array vem vazio.
  if (total === 0) return []
  return [
    { name: 'WhatsApp', percent: Math.round(((ch.whatsapp ?? 0) / total) * 100), color: '#22c55e' },
    { name: 'Email', percent: Math.round(((ch.email ?? 0) / total) * 100), color: '#06b6d4' },
    { name: 'Voice', percent: Math.round(((ch.voice ?? 0) / total) * 100), color: '#f59e0b' },
    { name: 'SMS', percent: Math.round(((ch.sms ?? 0) / total) * 100), color: '#0064ff' },
  ].sort((a, b) => b.percent - a.percent)
})

function channelColor(type: string): string {
  return { sms: '#0064ff', voice: '#f59e0b', email: '#06b6d4', whatsapp: '#22c55e' }[type] ?? '#8c90a2'
}

const chartOptions = computed(() => ({
  chart: { toolbar: { show: false }, fontFamily: "'Inter', sans-serif" },
  stroke: { curve: 'smooth', width: 2.5 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 100] } },
  colors: ['#0064ff'],
  xaxis: {
    categories: dailySliced.value.map(d => d.label),
    labels: { style: { fontSize: '11px', fontFamily: "'Inter', sans-serif", colors: '#8c90a2' } },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: { labels: { formatter: (v: number) => v.toLocaleString('pt-BR'), style: { colors: '#8c90a2' } } },
  tooltip: { y: { formatter: (v: number) => `${v.toLocaleString('pt-BR')} envios` } },
  grid: { borderColor: 'rgba(66,70,86,0.15)', strokeDashArray: 0, padding: { left: 0, right: 0 }, show: false },
  dataLabels: { enabled: false },
}))

const dailySliced = computed(() => {
  const all = stats.value?.daily_sent ?? []
  return chartPeriod.value < all.length ? all.slice(-chartPeriod.value) : all
})
const chartSeries = computed(() => [{ name: 'Envios', data: dailySliced.value.map(d => d.total) }])

const formatDate = (date: string) => { try { return new Date(date).toLocaleDateString('pt-BR') } catch { return '' } }
const statusLabel = (s: string) => ({ draft: 'Rascunho', scheduled: 'Agendada', processing: 'Processando', running: 'Em execução', completed: 'Concluída', failed: 'Falhou' }[s] ?? s)
</script>

<style scoped>
.bc-select {
  width: auto;
  border-radius: 8px;
  font-size: 0.82rem;
  background: var(--bc-gray-soft, rgba(66,70,86,0.08));
  border: none;
}
.bc-btn-refresh {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: none;
  background: var(--bc-gray-soft, rgba(66,70,86,0.08));
  color: var(--bc-text-muted, #6c7293);
  transition: all 0.2s ease;
}
.bc-btn-refresh:hover { color: var(--bc-primary, #0064ff); }

/* Fade-in animation for KPI cards */
.bc-fade-in {
  animation: bcFadeUp 0.4s ease both;
}
@keyframes bcFadeUp {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Spin animation for refresh */
.bc-spin { animation: bcSpin 0.8s linear infinite; }
@keyframes bcSpin { to { transform: rotate(360deg); } }

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .bc-fade-in { animation: none; }
  .bc-spin { animation: none; }
}
</style>
