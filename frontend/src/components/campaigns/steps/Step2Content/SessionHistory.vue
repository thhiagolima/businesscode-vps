<template>
  <div class="mt-4">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h4 class="mb-0">Histórico de sessões</h4>
      <button class="btn btn-ghost-secondary btn-sm" @click="load" :disabled="isLoading">
        <span v-if="isLoading" class="spinner-border spinner-border-sm me-1"></span>
        Atualizar
      </button>
    </div>
    <div v-if="isLoading" class="placeholder-glow">
      <div class="card mb-2"><div class="card-body"><span class="placeholder col-6"></span></div></div>
      <div class="card mb-2"><div class="card-body"><span class="placeholder col-5"></span></div></div>
    </div>
    <div v-else-if="!sessions.length" class="text-muted small">Nenhuma sessão encontrada.</div>
    <div v-else class="row g-2">
      <div class="col-12" v-for="session in sessions" :key="session.id">
        <div class="card">
          <div class="card-header py-2">
            <div class="d-flex align-items-center justify-content-between w-100">
              <div>
                <span class="fw-medium">Sessão #{{ session.id }}</span>
                <span class="badge bg-secondary-lt ms-2">{{ session.channel }}</span>
              </div>
              <span class="text-muted small">{{ formatDate(session.created_at) }}</span>
            </div>
          </div>
          <div class="card-body py-2" v-if="session.variations?.length">
            <div v-for="(v, vi) in session.variations" :key="vi"
                 class="d-flex align-items-start justify-content-between py-2"
                 :class="{ 'border-top': vi > 0 }">
              <div class="flex-fill me-3">
                <span class="badge bg-azure-lt me-1">v{{ vi + 1 }}</span>
                <span style="font-size:0.85rem;white-space:pre-wrap">{{ v.text }}</span>
              </div>
              <button class="btn btn-outline-primary btn-sm flex-shrink-0" @click="$emit('select', { text: v.text })">
                <i class="ti ti-check me-1"></i>Usar
              </button>
            </div>
          </div>
          <div class="card-body py-2 text-muted small" v-else>
            Nenhuma variação nesta sessão.
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useApi } from '@/composables/useApi'

interface Props {
  campaignId?: number | null
}
const props = defineProps<Props>()
defineEmits<{ select: [{ text: string }] }>()
const { get } = useApi()
const isLoading = ref(false)
const sessions = ref<any[]>([])

async function load() {
  if (!props.campaignId) {
    sessions.value = []
    return
  }
  isLoading.value = true
  try {
    const resp = await get<any>(`/campaigns/${props.campaignId}/ai-sessions`)
    const data = Array.isArray(resp) ? resp : (resp?.data ?? [])
    sessions.value = data.filter((s: any) => s.status === 'completed' && s.variations?.length)
  } catch {
    sessions.value = []
  } finally {
    isLoading.value = false
  }
}

function formatDate(d: string) {
  try { return new Date(d).toLocaleString('pt-BR') } catch { return d }
}

watch(() => props.campaignId, (newId) => {
  if (newId) load()
})

onMounted(() => {
  if (props.campaignId) load()
})
</script>
