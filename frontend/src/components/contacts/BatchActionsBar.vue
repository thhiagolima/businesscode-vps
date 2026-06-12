<template>
  <div class="alert alert-primary d-flex align-items-center gap-2 mb-3 py-2">
    <strong>{{ selectedCount }} selecionado{{ selectedCount > 1 ? 's' : '' }}</strong>
    <!-- Move dropdown -->
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown">
        <i class="ti ti-folder-symlink me-1"></i> Mover
      </button>
      <div class="dropdown-menu">
        <a v-for="list in lists" :key="list.id" class="dropdown-item" href="#" @click.prevent="$emit('move', list.id)">
          {{ list.name }}
        </a>
      </div>
    </div>
    <!-- Status dropdown -->
    <div class="dropdown">
      <button class="btn btn-sm btn-outline-light" data-bs-toggle="dropdown">
        <i class="ti ti-toggle-right me-1"></i> Status
      </button>
      <div class="dropdown-menu">
        <a class="dropdown-item" href="#" @click.prevent="$emit('status', 'active')">Ativo</a>
        <a class="dropdown-item" href="#" @click.prevent="$emit('status', 'blocked')">Bloqueado</a>
        <a class="dropdown-item" href="#" @click.prevent="$emit('status', 'invalid')">Inválido</a>
      </div>
    </div>
    <!-- Delete -->
    <button class="btn btn-sm btn-outline-danger" @click="$emit('delete')">
      <i class="ti ti-trash me-1"></i> Deletar
    </button>
    <!-- Clear -->
    <button class="btn btn-sm btn-ghost-light ms-auto" @click="$emit('clear')">
      <i class="ti ti-x"></i>
    </button>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  selectedCount: number
  lists: Array<{ id: number; name: string }>
}>()

defineEmits<{
  move: [listId: number]
  status: [status: string]
  delete: []
  clear: []
}>()
</script>
