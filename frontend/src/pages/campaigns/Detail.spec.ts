import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'

// --- Mocks das dependências externas do componente ---
const getMock = vi.fn()
const postMock = vi.fn(() => Promise.resolve({}))
const delMock = vi.fn(() => Promise.resolve({}))

vi.mock('@/composables/useApi', () => ({
  useApi: () => ({ get: getMock, post: postMock, del: delMock }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { tenant: { balance_cents: 0 } } }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' }, query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

import Detail from '@/pages/campaigns/Detail.vue'

/**
 * Configura o get() para responder cada endpoint que a Detail consulta.
 * Por padrão a campanha está CONCLUÍDA (status que reproduz o bug original:
 * o polling só rodava em 'running', então a tabela nunca era carregada).
 */
function setupApi(opts: { status?: string; dispatches?: any[]; campaign?: Record<string, any> } = {}) {
  const status = opts.status ?? 'completed'
  const dispatches = opts.dispatches ?? [
    { id: 10, phone: '+5521988194445', status: 'delivered', sent_at: '2026-06-07T12:00:00Z', delivered_at: '2026-06-07T12:00:05Z' },
  ]
  const campaign = { id: 1, name: 'Teste', type: 'sms', status, ...(opts.campaign ?? {}) }
  getMock.mockImplementation((url: string) => {
    if (String(url).includes('/dispatches')) {
      return Promise.resolve({ data: dispatches, meta: { last_page: 1 } })
    }
    if (String(url).startsWith('/reports/campaigns/')) {
      return Promise.resolve({ stats: { total: 1, sent: 1, delivered: 1, failed: 0 } })
    }
    if (String(url).startsWith('/campaigns/')) {
      return Promise.resolve(campaign)
    }
    return Promise.resolve({})
  })
}

describe('Detail.vue — aba Envios', () => {
  beforeEach(() => {
    getMock.mockReset()
    postMock.mockClear()
  })

  it('carrega os envios ao abrir a aba "Envios" mesmo com a campanha concluída', async () => {
    setupApi({ status: 'completed' })
    const wrapper = shallowMount(Detail)
    await flushPromises() // onMounted: loadCampaign + loadKpis

    getMock.mockClear()
    ;(wrapper.vm as any).activeTab = 'dispatches'
    await flushPromises()

    const carregouEnvios = getMock.mock.calls.some((c) => String(c[0]).includes('/dispatches'))
    expect(carregouEnvios).toBe(true)
  })
})

describe('Detail.vue — audiência na Visão Geral', () => {
  beforeEach(() => {
    getMock.mockReset()
  })

  it('mostra o NOME da lista de contatos, não o ID cru', async () => {
    setupApi({
      campaign: { contact_list_id: 7, contact_list: { id: 7, name: 'Lista VIP', contact_count: 50 } },
    })
    const wrapper = shallowMount(Detail)
    await flushPromises()

    expect(wrapper.text()).toContain('Lista VIP')
    // Não deve vazar o ID cru como valor da linha "Lista de contatos".
    expect(wrapper.text()).not.toContain('7')
  })

  it('mostra "Números digitados (N)" quando a audiência é ad-hoc (sem lista)', async () => {
    setupApi({
      campaign: { contact_list_id: null, settings: { adhoc_phones: ['+5521988194445', '+5521999990000'] } },
    })
    const wrapper = shallowMount(Detail)
    await flushPromises()

    expect(wrapper.text()).toContain('Números digitados (2)')
  })
})
