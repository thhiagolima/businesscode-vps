<template>
  <div class="card" style="min-width: 200px">
    <div class="card-header">
      <h3 class="card-title">Listas</h3>
    </div>
    <div class="list-group list-group-flush">
      <!-- "Todas" item -->
      <a class="list-group-item list-group-item-action d-flex justify-content-between"
         :class="{ active: selectedId === null }"
         href="#"
         @click.prevent="$emit('select', null)">
        <span>Todas</span>
        <span class="badge bg-secondary rounded-pill">{{ totalCount }}</span>
      </a>
      <!-- Each list -->
      <a v-for="list in lists" :key="list.id"
         class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
         :class="{ active: selectedId === list.id }"
         href="#"
         @click.prevent="$emit('select', list.id)">
        <!-- If editing this list -->
        <template v-if="editingId === list.id">
          <input class="form-control form-control-sm" v-model="editName"
                 @keyup.enter="saveRename(list.id)" @keyup.escape="editingId = null"
                 @blur="saveRename(list.id)" ref="editInput" style="max-width: 120px"
                 @click.stop>
        </template>
        <template v-else>
          <span class="text-truncate" style="max-width: 130px">{{ list.name }}</span>
        </template>
        <div class="d-flex align-items-center gap-1">
          <span class="badge bg-secondary rounded-pill">{{ list.contact_count }}</span>
          <div class="dropdown" @click.stop>
            <a class="text-muted" href="#" data-bs-toggle="dropdown"><i class="ti ti-dots-vertical" style="font-size:14px"></i></a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="#" @click.prevent="startRename(list)"><i class="ti ti-pencil me-2"></i>Renomear</a>
              <a class="dropdown-item text-danger" href="#" @click.prevent="deleteList(list.id)"><i class="ti ti-trash me-2"></i>Excluir</a>
            </div>
          </div>
        </div>
      </a>
    </div>
    <!-- New list form -->
    <div class="card-footer">
      <div v-if="showNewForm" class="input-group input-group-sm">
        <input class="form-control" v-model="newName" placeholder="Nome da lista" @keyup.enter="createList" @keyup.escape="showNewForm = false">
        <button class="btn btn-primary btn-sm" @click="createList" :disabled="!newName">
          <i class="ti ti-check"></i>
        </button>
      </div>
      <button v-else class="btn btn-outline-primary btn-sm w-100" @click="showNewForm = true">
        <i class="ti ti-plus me-1"></i> Nova lista
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  lists: Array<{ id: number; name: string; contact_count: number }>
  selectedId: number | null
}>()

const emit = defineEmits<{
  select: [id: number | null]
  created: []
  renamed: [id: number, name: string]
  deleted: [id: number]
}>()

const { post, put, del } = useApi()
const toast = useToast()

const totalCount = computed(() => props.lists.reduce((sum, l) => sum + l.contact_count, 0))

// Rename state
const editingId = ref<number | null>(null)
const editName = ref('')
const editInput = ref<HTMLInputElement[] | null>(null)

function startRename(list: { id: number; name: string }) {
  editingId.value = list.id
  editName.value = list.name
  nextTick(() => {
    if (editInput.value && editInput.value.length > 0) {
      editInput.value[0].focus()
    }
  })
}

async function saveRename(id: number) {
  if (!editName.value.trim() || editingId.value !== id) return
  try {
    await put(`/contact-lists/${id}`, { name: editName.value.trim() })
    emit('renamed', id, editName.value.trim())
    toast.success('Lista renomeada')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao renomear lista')
  } finally {
    editingId.value = null
  }
}

async function deleteList(id: number) {
  if (!confirm('Tem certeza que deseja excluir esta lista?')) return
  try {
    await del(`/contact-lists/${id}`)
    emit('deleted', id)
    toast.success('Lista excluída')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao excluir lista')
  }
}

// New list state
const showNewForm = ref(false)
const newName = ref('')

async function createList() {
  if (!newName.value.trim()) return
  try {
    await post('/contact-lists', { name: newName.value.trim() })
    emit('created')
    toast.success('Lista criada')
    newName.value = ''
    showNewForm.value = false
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao criar lista')
  }
}
</script>
