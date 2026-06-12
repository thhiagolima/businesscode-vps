<template>
  <div>
    <TableSkeleton v-if="loading && !items.length" :rows="5" :cols="6" />
    <EmptyState
      v-else-if="!items.length"
      icon="ti-users"
      title="Nenhum usuário"
      :description="emptyHint"
    />
    <div v-else class="table-responsive">
      <table class="table table-vcenter table-hover card-table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th class="text-center">Senha temp</th>
            <th>Criado</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in items" :key="u.id">
            <td class="fw-medium">{{ u.name }}</td>
            <td class="text-muted">{{ u.email }}</td>
            <td><span class="badge bg-secondary-lt">{{ u.role }}</span></td>
            <td><StatusBadge :label="u.status" :status="u.status" dot /></td>
            <td class="text-center">
              <span v-if="u.force_password_reset" class="badge bg-warning-lt" title="Reset de senha pendente">
                <i class="ti ti-key"></i>
              </span>
              <span v-else class="text-muted">—</span>
            </td>
            <td class="text-muted">{{ formatDate(u.created_at) }}</td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="$emit('action', { type: 'edit', item: u })" title="Editar"><i class="ti ti-pencil"></i></button>
                <button class="btn btn-sm btn-icon btn-ghost-warning" @click="$emit('action', { type: 'reset', item: u })" title="Resetar senha"><i class="ti ti-key"></i></button>
                <button
                  class="btn btn-sm btn-icon"
                  :class="u.status === 'active' ? 'btn-ghost-danger' : 'btn-ghost-success'"
                  @click="$emit('action', { type: u.status === 'active' ? 'suspend' : 'activate', item: u })"
                  :title="u.status === 'active' ? 'Suspender' : 'Ativar'"
                >
                  <i class="ti" :class="u.status === 'active' ? 'ti-lock' : 'ti-lock-open'"></i>
                </button>
                <button v-if="allowDelete" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'delete', item: u })" title="Excluir"><i class="ti ti-trash"></i></button>
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
