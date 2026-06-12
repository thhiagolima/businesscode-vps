<template>
  <div>
    <div class="page-header d-print-none">
      <div class="row align-items-center">
        <div class="col">
          <h2 class="page-title">{{ campaign?.campaign?.name ?? 'Detalhe da Campanha' }}</h2>
          <div class="mt-1">
            <StatusBadge class="me-2" :label="(campaign?.campaign?.type || '').toUpperCase()" :status="campaign?.campaign?.type || ''" />
            <StatusBadge :label="statusLabel(campaign?.campaign?.status || '')" :status="campaign?.campaign?.status || ''" />
          </div>
        </div>
        <div class="col-auto ms-auto">
          <div class="btn-list">
            <button class="btn btn-secondary" @click="exportCsv">
              <i class="ti ti-download me-1"></i>Exportar CSV
            </button>
            <button class="btn btn-primary" @click="duplicateCampaign">
              <i class="ti ti-copy me-1"></i>Duplicar campanha
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="row row-deck row-cards">
      <div class="col-sm-6 col-lg">
        <div class="card">
          <div class="card-body">
            <div class="subheader">Total dispatches</div>
            <div class="h1 mt-2 mb-0">{{ campaign?.stats?.total?.toLocaleString('pt-BR') ?? 0 }}</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg">
        <div class="card">
          <div class="card-body">
            <div class="subheader">Entregues</div>
            <div class="h1 mt-2 mb-0 text-success">{{ campaign?.stats?.delivered?.toLocaleString('pt-BR') ?? 0 }}</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg">
        <div class="card">
          <div class="card-body">
            <div class="subheader">Taxa de entrega</div>
            <div class="h1 mt-2 mb-0"
              :class="{
                'text-success': (campaign?.stats?.delivery_rate ?? 0) >= 90,
                'text-warning': (campaign?.stats?.delivery_rate ?? 0) >= 70 && (campaign?.stats?.delivery_rate ?? 0) < 90,
                'text-danger':  (campaign?.stats?.delivery_rate ?? 0) < 70,
              }">
              {{ campaign?.stats?.delivery_rate ?? 0 }}%
            </div>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg">
        <div class="card">
          <div class="card-body">
            <div class="subheader">Taxa de leitura</div>
            <div class="h1 mt-2 mb-0">{{ campaign?.stats?.read_rate ?? 0 }}%</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row row-deck row-cards mt-3">
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Timeline (últimas 24h)</h3>
          </div>
          <div class="card-body">
            <apexchart type="bar" height="280" :options="timelineOptions" :series="timelineSeries"></apexchart>
          </div>
        </div>
      </div>
    </div>

    <div class="row row-deck row-cards mt-3">
      <div class="col-12 col-md-6" v-if="(campaign?.errors?.length ?? 0) > 0">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Erros mais comuns</h3>
          </div>
          <div class="card-body">
            <div class="list-group list-group-flush">
              <div class="list-group-item" v-for="e in campaign?.errors ?? []" :key="e.message">
                <StatusBadge class="me-2" :label="String(e.count)" color="danger" variant="filled" />
                <span class="text-danger">{{ e.message }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 col-md-6">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Amostra de dispatches</h3>
          </div>
          <div class="table-responsive">
            <table class="table table-vcenter table-hover card-table">
              <thead>
                <tr>
                  <th>Contato</th>
                  <th>Telefone</th>
                  <th>Status</th>
                  <th>Enviado em</th>
                  <th>Entregue em</th>
                  <th>Erro</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in campaign?.dispatches_sample ?? []" :key="d.id">
                  <td>{{ d.contact_id }}</td>
                  <td>{{ d.phone }}</td>
                  <td><StatusBadge :label="statusLabel(d.status)" :status="d.status" /></td>
                  <td>{{ formatDate(d.sent_at) }}</td>
                  <td>{{ formatDate(d.delivered_at) }}</td>
                  <td>{{ d.error_message ?? '' }}</td>
                </tr>
                <tr v-if="!(campaign?.dispatches_sample?.length ?? 0)">
                  <td colspan="6" class="text-center text-muted py-3">Sem dados.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="card-footer">
            <button class="btn btn-ghost-secondary btn-sm" @click="router.push(`/campaigns/${id}?tab=dispatches`)">
              Ver todos os dispatches
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useReportStore } from '@/stores/report'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const route = useRoute()
const router = useRouter()
const store = useReportStore()
const { post } = useApi()
const toast = useToast()
const id = route.params.id as string
store.fetchCampaign(id)

const campaign = computed(() => store.currentCampaign)

const timelineOptions = computed(() => ({
  chart:  { type: 'bar', stacked: false, toolbar: { show: false }, fontFamily: 'inherit' },
  // Brighter blue/green/red so bars and legend swatches read on the dark theme.
  colors: ['#3b82f6', '#10b981', '#ef4444'],
  xaxis:  {
    categories: (campaign.value?.timeline ?? []).map((t: any) => t.hour),
    labels: { style: { colors: '#a4aabf' } },
    axisBorder: { color: 'rgba(255,255,255,0.12)' },
    axisTicks: { color: 'rgba(255,255,255,0.12)' },
  },
  yaxis:  { labels: { style: { colors: '#a4aabf' } } },
  // Default Apex legend/tooltip text is near-black — unreadable on dark.
  legend: { position: 'top', labels: { colors: '#dee0ff' } },
  tooltip: { theme: 'dark' },
  grid:    { borderColor: 'rgba(255,255,255,0.08)', strokeDashArray: 4 },
  dataLabels:  { enabled: false },
}))
const timelineSeries = computed(() => [
  { name: 'Enviados',   data: (campaign.value?.timeline ?? []).map((t: any) => t.sent) },
  { name: 'Entregues',  data: (campaign.value?.timeline ?? []).map((t: any) => t.delivered) },
  { name: 'Falhas',     data: (campaign.value?.timeline ?? []).map((t: any) => t.failed) },
])

const exportCsv = () => store.exportCampaign(id)
const duplicateCampaign = async () => {
  if (!campaign.value?.campaign) return
  try {
    const c = campaign.value.campaign
    const res = await post<any>('/campaigns', {
      name: c.name + ' (cópia)',
      type: c.type,
      content: c.content,
      subject: c.subject,
    })
    toast.success('Campanha duplicada')
    router.push('/campaigns/' + (res?.id ?? ''))
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao duplicar')
  }
}

const formatDate = (date: string) => {
  if (!date) return ''
  try {
    return new Date(date).toLocaleString('pt-BR')
  } catch {
    return ''
  }
}
const statusLabel = (s: string) => {
  const map: Record<string,string> = {
    draft: 'Rascunho',
    scheduled: 'Agendada',
    processing: 'Processando',
    running: 'Em execução',
    completed: 'Concluída',
    canceled: 'Cancelada',
    sent: 'Enviado',
    delivered: 'Entregue',
    failed: 'Falha',
    read: 'Lido',
    pending: 'Pendente',
  }
  return map[s] ?? s
}
</script>
