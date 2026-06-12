import { describe, it, expect } from 'vitest';
import { validatePage, MIN_WORDS } from '../src/lib/guardrails';

const longBody = 'palavra '.repeat(MIN_WORDS).trim();

describe('validatePage', () => {
  it('reprova página abaixo do mínimo de palavras', () => {
    const r = validatePage({ body: 'curto demais', uniqueFacts: ['x'] });
    expect(r.ok).toBe(false);
    expect(r.errors).toContain('CONTEUDO_CURTO');
  });

  it('reprova página sem ao menos um dado único', () => {
    const r = validatePage({ body: longBody, uniqueFacts: [] });
    expect(r.ok).toBe(false);
    expect(r.errors).toContain('SEM_DADO_UNICO');
  });

  it('aprova página com tamanho e dado único', () => {
    const r = validatePage({ body: longBody, uniqueFacts: ['Suporta 1000 msg/s'] });
    expect(r.ok).toBe(true);
    expect(r.errors).toHaveLength(0);
  });
});
