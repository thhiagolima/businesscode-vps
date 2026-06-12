<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Gerador de Conteúdo</h2>
    </div>

    <div class="card">
      <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <a class="nav-link" :class="{ active: tab === 'models' }" @click="tab = 'models'">Modelos salvos</a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link" :class="{ active: tab === 'history' }" @click="tab = 'history'">Histórico de gerações</a>
          </li>
        </ul>
      </div>
      <div class="card-body">
        <!-- Modelos salvos -->
        <div v-if="tab === 'models'">
          <div class="d-flex align-items-center gap-2 mb-3">
            <div class="btn-group" role="group" aria-label="Filtro de canal">
              <button class="btn" :class="channelFilter === null ? 'btn-primary' : 'btn-outline-primary'" @click="channelFilter = null">Todos</button>
              <button class="btn" :class="channelFilter === 'sms' ? 'btn-primary' : 'btn-outline-primary'" @click="channelFilter = 'sms'">SMS</button>
              <button class="btn" :class="channelFilter === 'voice' ? 'btn-primary' : 'btn-outline-primary'" @click="channelFilter = 'voice'">Voz</button>
              <button class="btn" :class="channelFilter === 'email' ? 'btn-primary' : 'btn-outline-primary'" @click="channelFilter = 'email'">Email</button>
            </div>
          </div>

          <div class="row row-cards">
            <div class="col-sm-6 col-lg-4" v-for="model in filteredModels" :key="model.id">
              <div class="card h-100">
                <div class="card-header">
                  <div class="d-flex align-items-center gap-2 w-100">
                    <span class="badge" :class="channelBadge(model.channel)">{{ channelLabel(model.channel) }}</span>
                    <span class="fw-medium text-truncate">{{ model.name }}</span>
                    <div class="ms-auto dropdown">
                      <button class="btn btn-ghost-secondary btn-sm btn-icon" data-bs-toggle="dropdown">
                        <i class="ti ti-dots-vertical"></i>
                      </button>
                      <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" @click="copyContent(model)">
                          <i class="ti ti-copy me-2"></i>Copiar conteúdo
                        </a>
                        <a class="dropdown-item" @click="useInNewCampaign(model)">
                          <i class="ti ti-speakerphone me-2"></i>Usar em nova campanha
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger" @click="deleteModel(model.id)">
                          <i class="ti ti-trash me-2"></i>Excluir
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="card-body">
                  <p class="text-muted small mb-0"
                     style="white-space: pre-wrap; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden">
                    {{ model.content }}
                  </p>
                </div>
                <div class="card-footer text-muted small">
                  <i class="ti ti-calendar me-1"></i>{{ formatDate(model.created_at) }}
                </div>
              </div>
            </div>
            <div v-if="filteredModels.length === 0" class="text-center text-muted py-5">
              <i class="ti ti-bookmark h1 d-block mb-2"></i>
              <div class="mb-1">Nenhum modelo salvo ainda</div>
              <div class="small">Salve variações geradas pela IA para reutilizar em futuras campanhas.</div>
            </div>
          </div>
        </div>

        <!-- Histórico de gerações -->
        <div v-else>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Data</th>
                  <th>Serviço</th>
                  <th>Status</th>
                  <th>Tokens</th>
                  <th>Custo USD</th>
                  <th>Custo (R$)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in history" :key="item.id">
                  <td>{{ formatDate(item.created_at) }}</td>
                  <td><span class="badge" :class="channelBadge(item.service)">{{ item.service }}</span></td>
                  <td>
                    <span class="badge"
                      :class="{
                        'bg-success': item.status === 'completed',
                        'bg-danger': item.status === 'failed',
                        'bg-warning text-dark': item.status === 'pending',
                      }">{{ item.status }}</span>
                  </td>
                  <td>{{ item.tokens_input }}/{{ item.tokens_output }}</td>
                  <td>${{ item.cost_usd }}</td>
                  <td>{{ brl(item.credits_used) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="text-muted small">Mostrando últimos {{ history.length }} itens</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useToast } from '@/composables/useToast'
import { useApi } from '@/composables/useApi'
import { brl } from '@/utils/currency'
import { useAiStore, type AiContentModel } from '@/stores/ai'
import router from '@/router'

const tab = ref<'models' | 'history'>('models')
const channelFilter = ref<'sms' | 'voice' | 'email' | null>(null)
const ai = useAiStore()
const { get } = useApi()
const toast = useToast()

const filteredModels = computed(() => {
  const list = ai.models
  return channelFilter.value ? list.filter(m => m.channel === channelFilter.value) : list
})

const history = ref<any[]>([])

function formatDate(d?: string) {
  if (!d) return ''
  return new Date(d).toLocaleString()
}

function channelLabel(c: string) {
  return { sms: 'SMS', voice: 'Voz', email: 'Email' }[c] ?? c
}

function channelBadge(c: string) {
  return {
    sms: 'bg-primary-lt text-primary',
    voice: 'bg-indigo-lt text-indigo',
    email: 'bg-azure-lt text-azure',
  }[c] ?? 'bg-secondary'
}

function copyContent(model: AiContentModel) {
  navigator.clipboard?.writeText(model.content)
  toast.success('Conteúdo copiado!')
}

function useInNewCampaign(model: AiContentModel) {
  // Redireciona para criação de campanha com canal pre-selecionado.
  router.push({ path: '/campaigns/create', query: { channel: model.channel, preset_content: model.content } })
}

async function loadHistory() {
  try {
    const resp = await get<any[]>('/ai/generations')
    history.value = Array.isArray((resp as any)?.data) ? (resp as any).data : (resp as any) ?? []
  } catch {
    toast.error('Falha ao carregar histórico')
  }
}

onMounted(async () => {
  await ai.fetchModels()
  await loadHistory()
})
</script>
