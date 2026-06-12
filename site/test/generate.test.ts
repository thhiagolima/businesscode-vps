import { describe, it, expect } from 'vitest';
import { buildEntry } from '../scripts/generate';

const longBody = 'palavra '.repeat(150).trim();

describe('buildEntry', () => {
  it('rejeita entrada que falha nos guardrails', () => {
    expect(() => buildEntry({ slug: 'x', title: 'T', description: 'D', body: 'curto', uniqueFacts: [], key: 'termo' }))
      .toThrow(/guardrail/i);
  });

  it('produz entrada válida com draft=false quando passa', () => {
    const e = buildEntry({ slug: 'webhook', title: 'Webhook', description: 'D', body: longBody, uniqueFacts: ['f'], key: 'termo' });
    expect(e.slug).toBe('webhook');
    expect(e.termo).toBe('webhook');
    expect(e.body).toBe(longBody);
  });
});
