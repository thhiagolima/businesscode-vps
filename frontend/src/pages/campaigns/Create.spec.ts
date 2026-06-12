import { describe, it, expect, vi, beforeEach } from 'vitest'
import { shallowMount, flushPromises } from '@vue/test-utils'

const getMock = vi.fn()
const postMock = vi.fn(() => Promise.resolve({}))
const putMock = vi.fn(() => Promise.resolve({}))

vi.mock('@/composables/useApi', () => ({
  useApi: () => ({ get: getMock, post: postMock, put: putMock }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { tenant: { balance_cents: 100000 } } }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: { id: '6', channel: 'sms' }, params: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

import Create from '@/pages/campaigns/Create.vue'

beforeEach(() => {
  getMock.mockReset()
  postMock.mockClear()
  putMock.mockClear()
  getMock.mockImplementation((url: string) => {
    if (String(url).startsWith('/account/pricing')) {
      return Promise.resolve([{ service: 'sms', sale_cents: 8 }])
    }
    if (String(url).startsWith('/campaigns/')) {
      // Campanha mínima (rascunho) — sem conteúdo/lista, para o wizard ficar no passo 1
      return Promise.resolve({ id: 6, name: 'Teste', type: 'sms', status: 'draft' })
    }
    return Promise.resolve({})
  })
})

describe('Create.vue — envio no passo de revisão', () => {
  it('dispara a campanha diretamente ao clicar "Enviar Campanha Agora" (sem modal)', async () => {
    const wrapper = shallowMount(Create)
    await flushPromises()

    // Vai para o passo 3 (Revisão e Envio)
    ;(wrapper.vm as any).currentStep = 3
    await flushPromises()

    const sendBtn = wrapper.findAll('button').find((b) => b.text().includes('Enviar Campanha Agora'))
    expect(sendBtn, 'botão de envio deve existir no passo 3').toBeTruthy()

    await sendBtn!.trigger('click')
    await flushPromises()

    const disparou = postMock.mock.calls.some((c) => String(c[0]).includes('/send-now'))
    expect(disparou, 'clique deve chamar /send-now diretamente').toBe(true)
  })
})

describe('Create.vue — passo de audiência sem botões redundantes', () => {
  it('não duplica navegação ("Revisar Campanha") nem oferece "Salvar Rascunho"', async () => {
    const wrapper = shallowMount(Create)
    await flushPromises()

    ;(wrapper.vm as any).currentStep = 2
    await flushPromises()

    const text = wrapper.text()
    expect(text).not.toContain('Revisar Campanha')
    expect(text).not.toContain('Salvar Rascunho')
    // A navegação para frente continua existindo (no rodapé do wizard).
    expect(text).toContain('Avançar')
  })
})
