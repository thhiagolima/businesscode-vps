# Skill: useApi

## Quando Usar
Em todo componente Vue que faz requisições HTTP.
Nunca importar ou usar `axios` diretamente.

## Instalação
Criar em `resources/js/composables/useApi.ts`:

```typescript
import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const client: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
    'Accept':       'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
})

export function useApi() {
  const auth   = useAuthStore()
  const router = useRouter()

  // Injeta token em toda requisição
  client.interceptors.request.use(config => {
    const token = auth.token
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  })

  // Trata respostas e erros globais
  client.interceptors.response.use(
    response => response.data?.data ?? response.data,
    error => {
      if (error.response?.status === 401) {
        auth.logout()
        router.push('/login')
      }
      return Promise.reject(error)
    }
  )

  const get = <T = unknown>(
    url: string,
    params?: Record<string, unknown>
  ): Promise<T> =>
    client.get(url, { params })

  const post = <T = unknown>(
    url: string,
    data?: Record<string, unknown> | FormData
  ): Promise<T> =>
    client.post(url, data)

  const put = <T = unknown>(
    url: string,
    data?: Record<string, unknown>
  ): Promise<T> =>
    client.put(url, data)

  const patch = <T = unknown>(
    url: string,
    data?: Record<string, unknown>
  ): Promise<T> =>
    client.patch(url, data)

  const del = <T = unknown>(url: string): Promise<T> =>
    client.delete(url)

  const upload = <T = unknown>(
    url: string,
    formData: FormData
  ): Promise<T> =>
    client.post(url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

  return { get, post, put, patch, del, upload }
}
```

## Uso nos Componentes

```typescript
import { useApi } from '@/composables/useApi'
import { ref, onMounted } from 'vue'

const { get, post, put, del } = useApi()

// GET com loading + error handling
const campaigns = ref([])
const isLoading = ref(false)

const fetchCampaigns = async () => {
  isLoading.value = true
  try {
    campaigns.value = await get('/campaigns')
  } catch (e) {
    toast.error('Erro ao carregar campanhas.')
  } finally {
    isLoading.value = false
  }
}

onMounted(fetchCampaigns)

// POST
const createCampaign = async (data: CampaignForm) => {
  isLoading.value = true
  try {
    const campaign = await post('/campaigns', data)
    toast.success('Campanha criada!')
    router.push(`/campaigns/${campaign.id}`)
  } catch (e: any) {
    const msg = e.response?.data?.message ?? 'Erro ao criar campanha.'
    toast.error(msg)
  } finally {
    isLoading.value = false
  }
}

// PUT
const updateCampaign = async (id: number, data: Partial<Campaign>) => {
  try {
    await put(`/campaigns/${id}`, data)
    toast.success('Salvo!')
  } catch {
    toast.error('Erro ao salvar.')
  }
}

// DELETE com confirmação
const deleteCampaign = async (id: number) => {
  if (!confirm('Confirma exclusão?')) return
  try {
    await del(`/campaigns/${id}`)
    campaigns.value = campaigns.value.filter(c => c.id !== id)
    toast.success('Campanha excluída.')
  } catch {
    toast.error('Erro ao excluir.')
  }
}

// Upload de arquivo (CSV, áudio)
const importCsv = async (file: File) => {
  const formData = new FormData()
  formData.append('file', file)
  try {
    const result = await upload('/contacts/import', formData)
    toast.success(`${result.imported} contatos importados.`)
  } catch {
    toast.error('Erro ao importar arquivo.')
  }
}
```

## Uso nas Stores (Pinia)

```typescript
// stores/campaign.ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type { Campaign } from '@/types'

export const useCampaignStore = defineStore('campaign', () => {
  const items     = ref<Campaign[]>([])
  const current   = ref<Campaign | null>(null)
  const isLoading = ref(false)

  const fetchAll = async () => {
    isLoading.value = true
    try {
      const { get } = useApi()
      items.value = await get<Campaign[]>('/campaigns')
    } finally {
      isLoading.value = false
    }
  }

  const fetchOne = async (id: number) => {
    const { get } = useApi()
    current.value = await get<Campaign>(`/campaigns/${id}`)
  }

  return { items, current, isLoading, fetchAll, fetchOne }
})
```

## Tratamento de Erros de Validação

```typescript
// Extrair erros de validação do Laravel (422)
const errors = ref<Record<string, string[]>>({})

const submit = async () => {
  errors.value = {}
  try {
    await post('/campaigns', form.value)
  } catch (e: any) {
    if (e.response?.status === 422) {
      errors.value = e.response.data.errors ?? {}
    } else {
      toast.error(e.response?.data?.message ?? 'Erro inesperado.')
    }
  }
}

// No template (com Tabler):
// <div class="invalid-feedback d-block" v-if="errors.name">
//   {{ errors.name[0] }}
// </div>
```

## Nunca Fazer
```typescript
// ❌ Nunca importar axios diretamente
import axios from 'axios'
axios.get('/api/v1/campaigns')

// ❌ Nunca chamar sem tratar erro
const data = await get('/campaigns')  // sem try/catch

// ❌ Nunca esquecer o loading state
post('/campaigns', data)  // sem isLoading
```
