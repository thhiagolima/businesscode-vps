<template>
  <div class="card" style="border-radius:14px">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title">Auditoria</h3>
      <div class="ms-auto d-flex gap-2">
        <input
          v-model="filters.action"
          class="form-control form-control-sm"
          placeholder="filtrar por action..."
          style="max-width:240px;border-radius:8px"
          @keyup.enter="load"
        />
        <button class="btn btn-sm btn-ghost-primary" @click="load" title="Filtrar">
          <i class="ti ti-search"></i>
        </button>
        <button class="btn btn-sm btn-ghost-secondary" @click="clearFilter" title="Limpar">
          <i class="ti ti-x"></i>
        </button>
      </div>
    </div>
    <div v-if="loading" class="card-body text-center py-4">
      <span class="spinner-border spinner-border-sm"></span>
    </div>
    <div v-else-if="!items.length" class="card-body text-center py-4 text-muted">
      Nenhum registro de auditoria.
    </div>
    <div v-else class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead>
          <tr>
            <th>Data</th>
            <th>Usuário</th>
            <th>Ação</th>
            <th>Recurso</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id">
            <td style="font-size:0.78rem">{{ formatDate(row.created_at) }}</td>
            <td style="font-family:'JetBrains Mono',monospace">{{ row.user_id ?? '—' }}</td>
            <td><code>{{ row.action }}</code></td>
            <td>
              {{ row.resource ?? '—' }}
              <span v-if="row.resource_id" class="text-muted">#{{ row.resource_id }}</span>
            </td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:0.78rem">{{ row.ip_address ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps<{ tenantId: number }>()
const { get } = useApi()
const items = ref<any[]>([])
const loading = ref(false)
const filters = reactive({ action: '' })

async function load() {
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (filters.action) params.set('action', filters.action)
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/audit?${params}`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

function clearFilter() {
  filters.action = ''
  load()
}

function formatDate(s?: string) {
  if (!s) return '—'
  return new Date(s).toLocaleString('pt-BR')
}

onMounted(load)
</script>
