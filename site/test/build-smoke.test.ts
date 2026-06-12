import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// ESM-safe dirname (package.json has "type":"module", __dirname is undefined)
const here = dirname(fileURLToPath(import.meta.url));
const dist = (p: string) => resolve(here, '..', 'dist', p);

describe('build output', () => {
  it('gera as páginas-chave', () => {
    for (const f of [
      'index.html',
      'blog.html',
      'precos.html',
      'glossario/webhook.html',
      'vs/twilio.html',
      'para/clinicas.html',
      'integracoes/rd-station.html',
      'robots.txt',
      'llms.txt',
      'sitemap-index.xml',
    ]) {
      expect(existsSync(dist(f)), `faltou ${f}`).toBe(true);
    }
  });

  it('o post do blog tem JSON-LD Article', () => {
    const html = readFileSync(dist('precos.html'), 'utf-8');
    expect(html).toContain('"@type":"FAQPage"');
  });
});
