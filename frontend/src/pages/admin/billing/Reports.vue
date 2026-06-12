<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">
          Relatórios Financeiros
        </h2>
        <span style="font-size:0.82rem;color:var(--bc-text-muted)">
          Visão consolidada de saldo, receita e margem.
        </span>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-ghost-secondary" :disabled="loading" @click="load">
          <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>
          Atualizar
        </button>
        <button
          class="btn btn-outline-primary"
          :disabled="!topConsumers.length"
          @click="exportCsv"
          title="Exporta a tabela visível como CSV"
        >
          <i class="ti ti-download me-1"></i>
          Exportar CSV
        </button>
      </div>
    </div>

    <div v-if="loading && !stats" class="card-body text-center py-5">
      <span class="spinner-border spinner-border-sm"></span>
    </div>

    <div v-else-if="!stats" class="card" style="border-radius:14px">
      <div class="card-body text-center py-5">
        <i class="ti ti-chart-bar-off" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">
          Sem dados disponíveis
        </h3>
      </div>
    </div>

    <template v-else>
      <!-- Stat cards -->
      <div class="row g-3 mb-4">
        <div class="col-md col-sm-6">
          <div class="card stat-card" style="border-radius:14px">
            <div class="card-body">
              <div class="stat-label">
                <i class="ti ti-wallet me-1"></i>
                Saldo total
              </div>
              <div class="stat-value">R$ {{ formatBrl(num('total_balance_brl')) }}</div>
              <div class="stat-hint">Somatório do saldo positivo dos tenants</div>
            </div>
          </div>
        </div>
        <div class="col-md col-sm-6">
          <div class="card stat-card" style="border-radius:14px">
            <div class="card-body">
              <div class="stat-label text-danger">
                <i class="ti ti-receipt-tax me-1"></i>
                Dívida acumulada
              </div>
              <div class="stat-value text-danger">R$ {{ formatBrl(num('total_owed_brl')) }}</div>
              <div class="stat-hint">Tenants em grace/suspended</div>
            </div>
          </div>
        </div>
        <div class="col-md col-sm-6">
          <div class="card stat-card" style="border-radius:14px">
            <div class="card-body">
              <div class="stat-label">
                <i class="ti ti-trending-up me-1"></i>
                MRR (30d)
              </div>
              <div class="stat-value">R$ {{ formatBrl(num('mrr_30d_brl')) }}</div>
              <div class="stat-hint">Receita recorrente dos últimos 30 dias</div>
            </div>
          </div>
        </div>
        <div class="col-md col-sm-6">
          <div class="card stat-card" style="border-radius:14px">
            <div class="card-body">
              <div class="stat-label">
                <i class="ti ti-coins me-1"></i>
                Margem (30d)
              </div>
              <div class="stat-value">R$ {{ formatBrl(num('margin_30d_brl')) }}</div>
              <div class="stat-hint">Venda − custo nos últimos 30 dias</div>
            </div>
          </div>
        </div>
        <div class="col-md col-sm-6">
          <div class="card stat-card" style="border-radius:14px">
            <div class="card-body">
              <div class="stat-label">
                <i class="ti ti-recharging me-1"></i>
                Taxa de recuperação
              </div>
              <div class="stat-value">{{ formatPct(num('recovery_rate')) }}</div>
              <div class="stat-hint">% de débitos cobrados com sucesso</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top 10 -->
      <div class="card" style="border-radius:14px">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h3 class="card-title">Top 10 — Consumo (mês corrente)</h3>
          <span style="font-size:0.78rem;color:var(--bc-text-muted)">
            {{ topConsumers.length }} tenants
          </span>
        </div>

        <div v-if="!topConsumers.length" class="card-body text-center py-5">
          <i class="ti ti-list" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
          <h3 style="font-size:1rem;margin-top:1rem;color:var(--bc-text-muted)">
            Nenhum consumo registrado neste mês
          </h3>
        </div>

        <div v-else class="table-responsive">
          <table class="table card-table table-vcenter">
            <thead>
              <tr>
                <th class="text-center w-1">#</th>
                <th>Tenant</th>
                <th class="text-end">Consumo (R$)</th>
                <th class="text-end">% do total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in topConsumers" :key="row.tenant_id">
                <td class="text-center text-muted">{{ i + 1 }}</td>
                <td>
                  <router-link
                    :to="`/admin/billing/tenants/${row.tenant_id}`"
                    style="text-decoration:none;font-weight:600"
                  >
                    {{ row.tenant_name }}
                  </router-link>
                  <div style="font-size:0.7rem;color:var(--bc-text-muted);font-family:'JetBrains Mono',monospace">
                    #{{ row.tenant_id }}
                  </div>
                </td>
                <td class="text-end" style="font-family:'JetBrains Mono',monospace">
                  R$ {{ formatBrl(row.consumed_brl) }}
                </td>
                <td class="text-end">
                  <div class="d-flex align-items-center justify-content-end gap-2">
                    <div
                      class="progress flex-fill"
                      style="max-width:120px;height:6px;background:var(--bc-gray-soft,rgba(255,255,255,0.06))"
                    >
                      <div
                        class="progress-bar bg-primary"
                        :style="{ width: pctOfTop(row.consumed_brl) + '%' }"
                      ></div>
                    </div>
                    <span style="font-size:0.78rem;color:var(--bc-text-muted);min-width:40px;text-align:right">
                      {{ pctOfTotal(row.consumed_brl) }}%
                    </span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useBillingStore } from '@/stores/billing'
import { useToast } from '@/composables/useToast'

const store = useBillingStore()
const toast = useToast()

const loading = ref(false)
const stats = computed(() => store.stats)

const topConsumers = computed(() => {
  const list = (store.stats?.top_consumers ?? []) as Array<{
    tenant_id: number
    tenant_name: string
    consumed_brl: number
  }>
  return list.slice(0, 10)
})

function num(key: string): number {
  const v = store.stats?.[key]
  const n = Number(v)
  return isNaN(n) ? 0 : n
}

function formatBrl(v: number): string {
  return v.toFixed(2).replace('.', ',')
}

function formatPct(v: number): string {
  // Backend may send 0-1 or 0-100; normalize
  const n = v > 1 ? v : v * 100
  return `${n.toFixed(1)}%`
}

const totalTop = computed(() => topConsumers.value.reduce((acc, r) => acc + Number(r.consumed_brl || 0), 0))
const maxTop = computed(() => topConsumers.value.reduce((m, r) => Math.max(m, Number(r.consumed_brl || 0)), 0))

function pctOfTotal(v: number): string {
  if (totalTop.value <= 0) return '0'
  return ((Number(v || 0) / totalTop.value) * 100).toFixed(1)
}

function pctOfTop(v: number): string {
  if (maxTop.value <= 0) return '0'
  return ((Number(v || 0) / maxTop.value) * 100).toFixed(0)
}

async function load() {
  loading.value = true
  try {
    await store.loadStats()
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Erro ao carregar estatísticas')
  } finally {
    loading.value = false
  }
}

function exportCsv() {
  if (!topConsumers.value.length) return
  const rows = [
    ['Posicao', 'Tenant ID', 'Tenant Nome', 'Consumo (R$)', 'Porcentagem do Total'],
    ...topConsumers.value.map((r, i) => [
      String(i + 1),
      String(r.tenant_id),
      r.tenant_name,
      formatBrl(Number(r.consumed_brl || 0)),
      pctOfTotal(Number(r.consumed_brl || 0)) + '%',
    ]),
  ]
  const csv = rows
    .map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
    .join('\n')
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  const stamp = new Date().toISOString().slice(0, 10)
  a.download = `billing-top-consumers-${stamp}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
  toast.success('CSV exportado')
}

onMounted(load)
</script>

<style scoped>
.stat-card .card-body {
  padding: 1rem 1.1rem;
}
.stat-label {
  font-size: 0.75rem;
  color: var(--bc-text-muted, #8c90a2);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.4rem;
}
.stat-value {
  font-size: 1.45rem;
  font-weight: 700;
  font-family: 'JetBrains Mono', monospace;
  letter-spacing: -0.02em;
  line-height: 1.1;
}
.stat-hint {
  font-size: 0.7rem;
  color: var(--bc-text-muted, #8c90a2);
  opacity: 0.7;
  margin-top: 0.25rem;
}
</style>
