import { describe, it, expect } from 'vitest'
import { brl } from '@/utils/currency'

describe('brl (smoke test do harness)', () => {
  it('formata centavos como BRL', () => {
    expect(brl(1234)).toBe('R$ 12,34')
  })
})
