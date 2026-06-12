<template>
  <div>
    <TableSkeleton v-if="loading && !items.length" :rows="5" :cols="6" />
    <EmptyState
      v-else-if="!items.length"
      icon="ti-address-book"
      title="Nenhum contato"
      :description="emptyHint"
    />
    <div v-else class="table-responsive">
      <table class="table table-vcenter table-hover card-table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Telefone</th>
            <th>Email</th>
            <th>Lista</th>
            <th>Status</th>
            <th>Criado</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.id">
            <td class="fw-medium">{{ c.name }}</td>
            <td style="font-family:'JetBrains Mono',monospace">{{ c.phone }}</td>
            <td class="text-muted">{{ c.email || '—' }}</td>
            <td class="text-muted">{{ c.contactList?.name ?? c.contact_list?.name ?? '—' }}</td>
            <td><StatusBadge :label="c.status" :status="c.status" dot /></td>
            <td class="text-muted">{{ formatDate(c.created_at) }}</td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="$emit('action', { type: 'edit', item: c })" title="Editar"><i class="ti ti-pencil"></i></button>
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

function formatDate(d?: string) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>
