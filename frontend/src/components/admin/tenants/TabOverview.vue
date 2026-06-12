<template>
  <div>
    <div class="row g-3 mb-3">
      <div class="col-md-3" v-for="card in cards" :key="card.label">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div style="font-size:0.78rem;color:var(--bc-text-muted);text-transform:uppercase;letter-spacing:0.05em">
              {{ card.label }}
            </div>
            <div style="font-size:1.5rem;font-weight:700;font-family:'JetBrains Mono',monospace">
              {{ card.value }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="tenant" class="card" style="border-radius:14px">
      <div class="card-header"><h3 class="card-title">Editar tenant</h3></div>
      <form @submit.prevent="save" class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nome</label>
            <input v-model="form.name" class="form-control" style="border-radius:10px" />
          </div>
          <div class="col-md-6">
            <label class="form-label">Slug</label>
            <input v-model="form.slug" class="form-control" style="border-radius:10px" />
          </div>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select v-model="form.status" class="form-select" style="border-radius:10px">
              <option value="trial">Trial</option>
              <option value="active">Ativo</option>
              <option value="suspended">Suspenso</option>
            </select>
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary" :disabled="saving">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useAdminTenantDetailStore } from '@/stores/adminTenantDetail'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ tenantId: number }>()
const store = useAdminTenantDetailStore()
const { tenant, kpis } = storeToRefs(store)
const { put } = useApi()
const toast = useToast()

const form = reactive({ name: '', slug: '', status: 'active' })
const saving = ref(false)

const cards = computed(() => [
  { label: 'Campanhas',         value: kpis.value?.campaigns_total ?? '—' },
  { label: 'Ativas',            value: kpis.value?.campaigns_active ?? '—' },
  { label: 'Contatos',          value: kpis.value?.contacts_total ?? '—' },
  { label: 'Msgs 30d',          value: kpis.value?.messages_sent_30d ?? '—' },
  { label: 'Entregues 30d',     value: kpis.value?.messages_delivered_30d ?? '—' },
  { label: 'Conversas abertas', value: kpis.value?.conversations_open ?? '—' },
  { label: 'Usuários',          value: kpis.value?.users_total ?? '—' },
])

async function save() {
  saving.value = true
  try {
    await put(`/admin/tenants/${props.tenantId}`, {
      name: form.name, slug: form.slug, status: form.status,
    })
    toast.success('Tenant atualizado')
    await store.loadOverview(props.tenantId)
    syncForm()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  } finally {
    saving.value = false
  }
}

function syncForm() {
  if (tenant.value) {
    form.name = tenant.value.name
    form.slug = tenant.value.slug
    form.status = tenant.value.status
  }
}

onMounted(() => {
  if (!tenant.value) store.loadOverview(props.tenantId).then(syncForm)
  else syncForm()
})
</script>
