<template>
  <div>
    <TableSkeleton v-if="loading && !items.length" :rows="5" :cols="6" />
    <EmptyState
      v-else-if="!items.length"
      icon="ti-messages"
      title="Nenhuma conversa"
      :description="emptyHint"
    />
    <div v-else class="table-responsive">
      <table class="table table-vcenter table-hover card-table">
        <thead>
          <tr>
            <th>Telefone</th>
            <th>Canal</th>
            <th>Status</th>
            <th class="text-end">Mensagens</th>
            <th>Última msg</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.id">
            <td style="font-family:'JetBrains Mono',monospace">{{ c.phone }}</td>
            <td><span class="badge bg-secondary-lt">{{ c.channel }}</span></td>
            <td><StatusBadge :label="c.status" :status="c.status" dot /></td>
            <td class="text-end" style="font-family:'JetBrains Mono',monospace">{{ c.messages_count ?? '—' }}</td>
            <td class="text-muted">{{ formatDate(c.last_message_at) }}</td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="$emit('action', { type: 'view', item: c })" title="Ver"><i class="ti ti-eye"></i></button>
                <button v-if="c.status !== 'closed'" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'close', item: c })" title="Encerrar"><i class="ti ti-square"></i></button>
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

function formatDate(d?: string) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>
