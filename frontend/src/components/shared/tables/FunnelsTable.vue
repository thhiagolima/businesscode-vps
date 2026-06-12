<template>
  <div>
    <TableSkeleton v-if="loading && !items.length" :rows="5" :cols="5" />
    <EmptyState
      v-else-if="!items.length"
      icon="ti-route"
      title="Nenhum funil"
      :description="emptyHint"
    />
    <div v-else class="table-responsive">
      <table class="table table-vcenter table-hover card-table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Status</th>
            <th class="text-end">Execuções</th>
            <th>Criado</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in items" :key="f.id">
            <td class="fw-medium">{{ f.name }}</td>
            <td><span class="badge bg-secondary-lt">{{ f.status }}</span></td>
            <td class="text-end" style="font-family:'JetBrains Mono',monospace">{{ f.executions_count ?? 0 }}</td>
            <td class="text-muted">{{ formatDate(f.created_at) }}</td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="$emit('action', { type: 'view', item: f })" title="Editar"><i class="ti ti-pencil"></i></button>
                <button class="btn btn-sm btn-icon btn-ghost-primary" @click="$emit('action', { type: 'toggle', item: f })" :title="f.status === 'active' ? 'Pausar' : 'Ativar'">
                  <i class="ti" :class="f.status === 'active' ? 'ti-toggle-left' : 'ti-toggle-right'"></i>
                </button>
                <button v-if="allowDelete" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'delete', item: f })" title="Excluir"><i class="ti ti-trash"></i></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'

defineProps<{
  items: any[]
  loading?: boolean
  allowDelete?: boolean
  emptyHint?: string
}>()
defineEmits<{ (e: 'action', payload: { type: string; item: any }): void }>()

function formatDate(d?: string) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>
