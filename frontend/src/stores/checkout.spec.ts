import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const getMock = vi.fn()
const postMock = vi.fn()

vi.mock('@/composables/useApi', () => ({
  useApi: () => ({ get: getMock, post: postMock }),
}))

import { useCheckoutStore } from '@/stores/checkout'

beforeEach(() => {
  setActivePinia(createPinia())
  getMock.mockReset()
  postMock.mockReset()
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('checkout store — pollPaymentStatus', () => {
  it('consulta /payments/{id}/status e chama onApproved quando aprovado', async () => {
    const store = useCheckoutStore()
    getMock.mockResolvedValue({ status: 'approved' })
    const onApproved = vi.fn()

    store.pollPaymentStatus(123, onApproved)
    await vi.advanceTimersByTimeAsync(5000)

    expect(getMock).toHaveBeenCalledWith('/payments/123/status')
    expect(onApproved).toHaveBeenCalledTimes(1)
    expect(store.paymentStatus).toBe('success')
  })

  it('não chama onApproved enquanto o pagamento está pendente', async () => {
    const store = useCheckoutStore()
    getMock.mockResolvedValue({ status: 'pending' })
    const onApproved = vi.fn()

    store.pollPaymentStatus(123, onApproved)
    await vi.advanceTimersByTimeAsync(15000)

    expect(getMock.mock.calls.length).toBeGreaterThanOrEqual(2)
    expect(onApproved).not.toHaveBeenCalled()
  })

  it('para o polling após aprovar (não continua consultando)', async () => {
    const store = useCheckoutStore()
    getMock.mockResolvedValue({ status: 'approved' })
    const onApproved = vi.fn()

    store.pollPaymentStatus(123, onApproved)
    await vi.advanceTimersByTimeAsync(5000)
    const callsAfterApproval = getMock.mock.calls.length
    await vi.advanceTimersByTimeAsync(20000)

    expect(getMock.mock.calls.length).toBe(callsAfterApproval)
    expect(onApproved).toHaveBeenCalledTimes(1)
  })
})

describe('checkout store — pollSubscriptionActive', () => {
  it('chama onActive quando /subscriptions/current passa a retornar a assinatura', async () => {
    const store = useCheckoutStore()
    getMock.mockResolvedValueOnce(null).mockResolvedValue({ id: 7, status: 'active' })
    const onActive = vi.fn()

    store.pollSubscriptionActive(onActive)

    await vi.advanceTimersByTimeAsync(5000) // 1ª consulta: ainda pendente (null)
    expect(onActive).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(5000) // 2ª consulta: ativa
    expect(getMock).toHaveBeenCalledWith('/subscriptions/current')
    expect(onActive).toHaveBeenCalledTimes(1)
    expect(store.paymentStatus).toBe('success')
  })
})
