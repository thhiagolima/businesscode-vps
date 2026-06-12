<template>
  <div>
    <div class="row g-3 mb-3" v-if="data">
      <div v-for="row in data.by_channel_30d" :key="row.channel" class="col-md-3">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div style="font-size:0.78rem;color:var(--bc-text-muted);text-transform:uppercase">{{ row.channel }}</div>
            <div style="font-size:1.4rem;font-weight:700;font-family:'JetBrains Mono',monospace">{{ row.sent }}</div>
            <div style="font-size:0.7rem;color:var(--bc-text-muted)">
              {{ row.delivered }} entregues · {{ row.failed }} falhas
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="border-radius:14px" v-if="data">
      <div class="card-header"><h3 class="card-title">Top 5 campanhas (30d)</h3></div>
      <div class="table-responsive">
        <table class="table card-table">
          <thead>
            <tr>
              <th>Campanha</th>
              <th class="text-end">Enviadas</th>
              <th class="text-end">Taxa entrega</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in data.top_campaigns_30d" :key="c.id">
              <td>{{ c.name }}</td>
              <td class="text-end" style="font-family:'JetBrains Mono',monospace">{{ c.sent }}</td>
              <td class="text-end">{{ (c.delivered_rate * 100).toFixed(1) }}%</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="!data && !loading" class="card-body text-center text-muted py-4">
      Sem dados.
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps<{ tenantId: number }>()
const { get } = useApi()
const data = ref<any | null>(null)
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/reports`)
    data.value = resp.data ?? resp
  } finally {
    loading.value = false
  }
})
</script>
