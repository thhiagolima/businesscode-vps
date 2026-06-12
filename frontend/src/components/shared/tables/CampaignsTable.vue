<template>
  <div>
    <TableSkeleton v-if="loading && !items.length" :rows="5" :cols="6" />
    <EmptyState
      v-else-if="!items.length"
      icon="ti-bullhorn"
      title="Nenhuma campanha"
      :description="emptyHint"
    />
    <div v-else class="table-responsive">
      <table class="table table-vcenter table-hover card-table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Tipo</th>
            <th>Status</th>
            <th class="text-end">Enviadas</th>
            <th>Criada</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.id">
            <td class="fw-medium">{{ c.name }}</td>
            <td><span class="badge bg-secondary-lt">{{ c.type }}</span></td>
            <td><StatusBadge :label="c.status" :status="c.status" dot /></td>
            <td class="text-end" style="font-family:'JetBrains Mono',monospace">{{ c.sent_count ?? 0 }}</td>
            <td class="text-muted">{{ formatDate(c.created_at) }}</td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button v-if="canPause(c)" class="btn btn-sm btn-icon btn-ghost-warning" @click="$emit('action', { type: 'pause', item: c })" title="Pausar"><i class="ti ti-player-pause"></i></button>
                <button v-if="canResume(c)" class="btn btn-sm btn-icon btn-ghost-success" @click="$emit('action', { type: 'resume', item: c })" title="Retomar"><i class="ti ti-player-play"></i></button>
                <button v-if="canCancel(c)" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'cancel', item: c })" title="Cancelar"><i class="ti ti-square"></i></button>
                <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="$emit('action', { type: 'view', item: c })" title="Ver"><i class="ti ti-eye"></i></button>
                <button v-if="allowDelete" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'delete', item: c })" title="Excluir"><i class="ti ti-trash"></i></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'

defineProps<{
  items: any[]
  loading?: boolean
  allowDelete?: boolean
  emptyHint?: string
}>()
defineEmits<{ (e: 'action', payload: { type: string; item: any }): void }>()

function canPause(c: any) { return ['scheduled', 'processing', 'running'].includes(c.status) }
function canResume(c: any) { return c.status === 'paused' }
function canCancel(c: any) { return !['completed', 'failed', 'draft'].includes(c.status) }

function formatDate(d?: string) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>
