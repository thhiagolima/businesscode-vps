import { describe, it, expect } from 'vitest';
import { articleJsonLd, faqJsonLd, organizationJsonLd } from '../src/lib/seo';

const BASE_INPUT = {
  title: 'Como usar a API do WhatsApp',
  description: 'Guia completo',
  url: 'https://businesscode.com.br/blog/api-whatsapp',
  datePublished: '2026-06-10',
  author: 'BusinessCode',
};

describe('articleJsonLd', () => {
  it('produz schema Article com campos obrigatórios', () => {
    const ld = articleJsonLd(BASE_INPUT);
    expect(ld['@type']).toBe('Article');
    expect(ld.headline).toBe('Como usar a API do WhatsApp');
    expect(ld.author.name).toBe('BusinessCode');
  });

  it('mainEntityOfPage é um nó WebPage com @id correto', () => {
    const ld = articleJsonLd(BASE_INPUT);
    expect(ld.mainEntityOfPage['@type']).toBe('WebPage');
    expect(ld.mainEntityOfPage['@id']).toBe('https://businesscode.com.br/blog/api-whatsapp');
  });

  it('publisher não carrega @context (apenas o nó Organization)', () => {
    const ld = articleJsonLd(BASE_INPUT);
    expect((ld.publisher as Record<string, unknown>)['@context']).toBeUndefined();
    expect(ld.publisher['@type']).toBe('Organization');
  });

  it('dateModified usa datePublished quando omitido', () => {
    const ld = articleJsonLd(BASE_INPUT);
    expect(ld.dateModified).toBe('2026-06-10');
  });

  it('dateModified usa o valor fornecido quando presente', () => {
    const ld = articleJsonLd({ ...BASE_INPUT, dateModified: '2026-06-15' });
    expect(ld.dateModified).toBe('2026-06-15');
  });
});

describe('faqJsonLd', () => {
  it('produz FAQPage com mainEntity por pergunta', () => {
    const ld = faqJsonLd([{ question: 'O que é?', answer: 'É isso.' }]);
    expect(ld['@type']).toBe('FAQPage');
    expect(ld.mainEntity).toHaveLength(1);
    expect(ld.mainEntity[0]['@type']).toBe('Question');
    expect(ld.mainEntity[0].acceptedAnswer.text).toBe('É isso.');
  });
});

describe('organizationJsonLd', () => {
  it('produz Organization com nome e url', () => {
    const ld = organizationJsonLd();
    expect(ld['@type']).toBe('Organization');
    expect(ld.name).toBe('BusinessCode');
    expect(ld.url).toBe('https://businesscode.com.br');
  });

  it('inclui @context na raiz do documento', () => {
    const ld = organizationJsonLd();
    expect(ld['@context']).toBe('https://schema.org');
  });

  it('logo aponta para /favicon.svg', () => {
    const ld = organizationJsonLd();
    expect(ld.logo).toBe('https://businesscode.com.br/favicon.svg');
  });
});
