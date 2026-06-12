import { describe, it, expect } from 'vitest'
import { extractApiError } from '@/utils/apiError'

describe('extractApiError', () => {
  it('prefere o primeiro erro de campo (errors como objeto Laravel)', () => {
    const e = {
      response: {
        data: {
          message: 'Erro de validação',
          errors: { type: ["O canal 'sms' não está habilitado para a sua conta."] },
        },
      },
    }
    expect(extractApiError(e, 'fallback')).toBe("O canal 'sms' não está habilitado para a sua conta.")
  })

  it('junta erros quando errors é um array', () => {
    const e = { response: { data: { message: 'x', errors: ['Saldo insuficiente', 'Sem destinatários'] } } }
    expect(extractApiError(e, 'fallback')).toBe('Saldo insuficiente. Sem destinatários')
  })

  it('cai para message quando não há errors', () => {
    const e = { response: { data: { message: 'Recurso não encontrado' } } }
    expect(extractApiError(e, 'fallback')).toBe('Recurso não encontrado')
  })

  it('cai para o fallback quando não há response (erro de rede)', () => {
    expect(extractApiError(new Error('Network Error'), 'Verifique sua conexão.')).toBe('Verifique sua conexão.')
  })

  it('ignora errors vazio e usa message', () => {
    const e = { response: { data: { message: 'Erro de validação', errors: {} } } }
    expect(extractApiError(e, 'fallback')).toBe('Erro de validação')
  })
})
