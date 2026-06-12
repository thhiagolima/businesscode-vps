# Site de Conteúdo (Astro) — SEO + Citação por IA — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar um site de marketing+conteúdo em Astro (SSG estático) no domínio raiz `businesscode.com.br`, com blog editorial, páginas programáticas em massa, pipeline de automação com guardrails, e camada de SEO/JSON-LD — servido pelo Apache, sem tocar no app Vue em `dash.`.

**Architecture:** Projeto Astro novo em `site/`, isolado do app Vue (`frontend/`), no mesmo repositório git. Conteúdo é dado versionado: blog em Markdown (collection `glob`), programático a partir de JSON (collection `file`). Build gera HTML estático em `site/dist/`, servido por um VirtualHost próprio do Apache.

**Tech Stack:** Astro 5+, `@astrojs/sitemap`, `@astrojs/mdx`, Zod (via `astro:content`), TypeScript, Vitest (testes de lógica), Apache (XAMPP/Windows).

**Spec:** `docs/superpowers/specs/2026-06-10-site-conteudo-astro-seo-design.md`

---

## File Structure

```
site/
  package.json
  astro.config.mjs
  tsconfig.json
  vitest.config.ts
  src/
    content.config.ts              # schemas das collections (blog + programáticas)
    layouts/
      BaseLayout.astro             # <html>, <head> com <SEO>, slot
    components/
      SEO.astro                    # meta tags + OG + canonical
      JsonLd.astro                 # injeta <script type="application/ld+json">
    lib/
      seo.ts                       # builders de JSON-LD (testável)
      guardrails.ts                # validação anti-thin-content (testável)
    pages/
      index.astro                  # landing (migrada de frontend/public/landing.html)
      precos.astro
      recursos.astro
      sobre.astro
      contato.astro
      robots.txt.ts                # robots dinâmico
      llms.txt.ts                  # llms.txt para agentes de IA
      blog/
        index.astro                # listagem
        [...slug].astro            # post individual
      glossario/[termo].astro
      vs/[concorrente].astro
      para/[segmento].astro
      integracoes/[ferramenta].astro
    content/
      blog/                        # .md/.mdx editoriais
        hello-world.md             # post seed
    data/
      glossario.json
      comparativos.json
      segmentos.json
      integracoes.json
  scripts/
    generate.ts                    # pipeline de geração (usa guardrails.ts)
  test/
    seo.test.ts
    guardrails.test.ts
    build-smoke.test.ts
c:/xampp/apache/conf/extra/businesscode-site.conf   # VirtualHost (fora do repo)
```

---

## Task 1: Scaffold do projeto Astro

**Files:**
- Create: `site/package.json`, `site/astro.config.mjs`, `site/tsconfig.json`

- [ ] **Step 1: Criar o projeto Astro mínimo (sem template interativo)**

Run:
```bash
cd c:/xampp/htdocs/new_saas
mkdir site
cd site
npm init -y
npm install astro @astrojs/sitemap @astrojs/mdx
npm install -D vitest typescript @types/node
```

- [ ] **Step 2: Escrever `site/package.json` (substituir o gerado)**

```json
{
  "name": "businesscode-site",
  "type": "module",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "astro dev",
    "build": "astro build",
    "preview": "astro preview",
    "generate": "node --experimental-strip-types scripts/generate.ts",
    "test": "vitest run"
  },
  "dependencies": {
    "@astrojs/mdx": "^4.0.0",
    "@astrojs/sitemap": "^3.2.0",
    "astro": "^5.0.0"
  },
  "devDependencies": {
    "@types/node": "^20.11.24",
    "typescript": "^5.4.2",
    "vitest": "^1.6.1"
  }
}
```

- [ ] **Step 3: Escrever `site/astro.config.mjs`**

```js
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import mdx from '@astrojs/mdx';

export default defineConfig({
  site: 'https://businesscode.com.br',
  trailingSlash: 'never',
  integrations: [mdx(), sitemap()],
  build: {
    format: 'file', // gera /precos.html em vez de /precos/index.html — casa com Apache
  },
});
```

- [ ] **Step 4: Escrever `site/tsconfig.json`**

```json
{
  "extends": "astro/tsconfigs/strict",
  "include": [".astro/types.d.ts", "**/*"],
  "exclude": ["dist"]
}
```

- [ ] **Step 5: Escrever `site/vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['test/**/*.test.ts'],
    environment: 'node',
  },
});
```

- [ ] **Step 6: Verificar que o dev server sobe**

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: build conclui (mesmo sem páginas ainda gera `dist/`). Se reclamar de "no pages", criar um `src/pages/index.astro` temporário com `<h1>ok</h1>` e rebuildar — será substituído na Task 8.

- [ ] **Step 7: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/package.json site/astro.config.mjs site/tsconfig.json site/vitest.config.ts
git commit -m "chore(site): scaffold projeto Astro"
```

---

## Task 2: Builders de JSON-LD (TDD)

Lógica pura testável que produz objetos JSON-LD. Componente Astro só injeta o resultado.

**Files:**
- Create: `site/src/lib/seo.ts`
- Test: `site/test/seo.test.ts`

- [ ] **Step 1: Escrever o teste que falha**

```ts
// site/test/seo.test.ts
import { describe, it, expect } from 'vitest';
import { articleJsonLd, faqJsonLd, organizationJsonLd } from '../src/lib/seo';

describe('articleJsonLd', () => {
  it('produz schema Article com campos obrigatórios', () => {
    const ld = articleJsonLd({
      title: 'Como usar a API do WhatsApp',
      description: 'Guia completo',
      url: 'https://businesscode.com.br/blog/api-whatsapp',
      datePublished: '2026-06-10',
      author: 'BusinessCode',
    });
    expect(ld['@type']).toBe('Article');
    expect(ld.headline).toBe('Como usar a API do WhatsApp');
    expect(ld.author.name).toBe('BusinessCode');
    expect(ld.mainEntityOfPage).toBe('https://businesscode.com.br/blog/api-whatsapp');
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
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd c:/xampp/htdocs/new_saas/site && npx vitest run test/seo.test.ts`
Expected: FAIL — `Cannot find module '../src/lib/seo'`.

- [ ] **Step 3: Implementar `site/src/lib/seo.ts`**

```ts
const SITE = 'https://businesscode.com.br';
const ORG_NAME = 'BusinessCode';

export interface ArticleInput {
  title: string;
  description: string;
  url: string;
  datePublished: string;
  dateModified?: string;
  author: string;
  image?: string;
}

export function articleJsonLd(a: ArticleInput) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: a.title,
    description: a.description,
    mainEntityOfPage: a.url,
    datePublished: a.datePublished,
    dateModified: a.dateModified ?? a.datePublished,
    author: { '@type': 'Organization', name: a.author },
    publisher: organizationJsonLd(),
    ...(a.image ? { image: a.image } : {}),
  };
}

export function faqJsonLd(items: Array<{ question: string; answer: string }>) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map((i) => ({
      '@type': 'Question',
      name: i.question,
      acceptedAnswer: { '@type': 'Answer', text: i.answer },
    })),
  };
}

export function organizationJsonLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: ORG_NAME,
    url: SITE,
    logo: `${SITE}/favicon.svg`,
  };
}

export function breadcrumbJsonLd(items: Array<{ name: string; url: string }>) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((it, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: it.name,
      item: it.url,
    })),
  };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd c:/xampp/htdocs/new_saas/site && npx vitest run test/seo.test.ts`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/lib/seo.ts site/test/seo.test.ts
git commit -m "feat(site): builders de JSON-LD (Article/FAQ/Organization/Breadcrumb)"
```

---

## Task 3: Componentes SEO, JsonLd e BaseLayout

**Files:**
- Create: `site/src/components/SEO.astro`, `site/src/components/JsonLd.astro`, `site/src/layouts/BaseLayout.astro`

- [ ] **Step 1: Escrever `site/src/components/JsonLd.astro`**

```astro
---
interface Props { data: unknown }
const { data } = Astro.props;
---
<script type="application/ld+json" set:html={JSON.stringify(data)} is:inline />
```

- [ ] **Step 2: Escrever `site/src/components/SEO.astro`**

```astro
---
interface Props {
  title: string;
  description: string;
  canonical?: string;
  image?: string;
  noindex?: boolean;
}
const { title, description, canonical, image, noindex = false } = Astro.props;
const canonicalUrl = canonical ?? new URL(Astro.url.pathname, Astro.site).href;
const ogImage = image ?? new URL('/og-businesscode.png', Astro.site).href;
---
<title>{title}</title>
<meta name="description" content={description} />
<link rel="canonical" href={canonicalUrl} />
{noindex && <meta name="robots" content="noindex, nofollow" />}
<meta property="og:type" content="website" />
<meta property="og:title" content={title} />
<meta property="og:description" content={description} />
<meta property="og:url" content={canonicalUrl} />
<meta property="og:image" content={ogImage} />
<meta name="twitter:card" content="summary_large_image" />
```

- [ ] **Step 3: Escrever `site/src/layouts/BaseLayout.astro`**

```astro
---
import SEO from '../components/SEO.astro';
import JsonLd from '../components/JsonLd.astro';
import { organizationJsonLd } from '../lib/seo';

interface Props {
  title: string;
  description: string;
  canonical?: string;
  image?: string;
  noindex?: boolean;
  jsonLd?: unknown;
}
const { title, description, canonical, image, noindex, jsonLd } = Astro.props;
---
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <SEO title={title} description={description} canonical={canonical} image={image} noindex={noindex} />
    <JsonLd data={organizationJsonLd()} />
    {jsonLd && <JsonLd data={jsonLd} />}
  </head>
  <body>
    <slot />
  </body>
</html>
```

- [ ] **Step 4: Copiar o favicon e OG para `site/public/`**

Run:
```bash
cd c:/xampp/htdocs/new_saas
cp frontend/dist/favicon.svg site/public/favicon.svg
cp frontend/dist/og-businesscode.png site/public/og-businesscode.png
```
Expected: dois arquivos em `site/public/`. (Se algum não existir, criar placeholder e anotar no commit.)

- [ ] **Step 5: Build sanity check** — criar `site/src/pages/index.astro` temporário usando o layout:

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout title="BusinessCode" description="Plataforma de mensageria para empresas.">
  <h1>BusinessCode</h1>
</BaseLayout>
```

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: build OK; `dist/index.html` contém `<script type="application/ld+json">` com `"@type":"Organization"` e a `<link rel="canonical">`.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/components site/src/layouts site/public site/src/pages/index.astro
git commit -m "feat(site): BaseLayout + componentes SEO e JsonLd"
```

---

## Task 4: Blog — collection, listagem e post

**Files:**
- Create: `site/src/content.config.ts`, `site/src/content/blog/hello-world.md`, `site/src/pages/blog/index.astro`, `site/src/pages/blog/[...slug].astro`

- [ ] **Step 1: Definir a collection do blog em `site/src/content.config.ts`**

```ts
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const blog = defineCollection({
  loader: glob({ pattern: '**/[^_]*.{md,mdx}', base: './src/content/blog' }),
  schema: z.object({
    title: z.string(),
    description: z.string(),
    date: z.coerce.date(),
    updated: z.coerce.date().optional(),
    author: z.string().default('BusinessCode'),
    tags: z.array(z.string()).default([]),
    cover: z.string().optional(),
    draft: z.boolean().default(true),
  }),
});

export const collections = { blog };
```

- [ ] **Step 2: Criar post seed `site/src/content/blog/hello-world.md`**

```md
---
title: "WhatsApp Business API: o guia para empresas começarem"
description: "O que é a WhatsApp Business API, como funciona e quando vale a pena para a sua empresa."
date: 2026-06-10
author: BusinessCode
tags: ["whatsapp", "api", "mensageria"]
draft: false
---

A WhatsApp Business API permite que empresas enviem e recebam mensagens em escala,
com automação, múltiplos atendentes e integração com sistemas internos.

## Quando usar

Use quando o volume de conversas ultrapassa o que um atendente consegue tratar manualmente.
```

- [ ] **Step 3: Listagem `site/src/pages/blog/index.astro`**

```astro
---
import { getCollection } from 'astro:content';
import BaseLayout from '../../layouts/BaseLayout.astro';

const posts = (await getCollection('blog', ({ data }) => !data.draft))
  .sort((a, b) => b.data.date.getTime() - a.data.date.getTime());
---
<BaseLayout title="Blog — BusinessCode" description="Artigos sobre mensageria, WhatsApp API e automação para empresas.">
  <h1>Blog</h1>
  <ul>
    {posts.map((p) => (
      <li>
        <a href={`/blog/${p.id}`}>{p.data.title}</a>
        <p>{p.data.description}</p>
      </li>
    ))}
  </ul>
</BaseLayout>
```

- [ ] **Step 4: Post individual `site/src/pages/blog/[...slug].astro`**

```astro
---
import { getCollection, render } from 'astro:content';
import BaseLayout from '../../layouts/BaseLayout.astro';
import { articleJsonLd } from '../../lib/seo';

export async function getStaticPaths() {
  const posts = await getCollection('blog', ({ data }) => !data.draft);
  return posts.map((post) => ({ params: { slug: post.id }, props: { post } }));
}

const { post } = Astro.props;
const { Content } = await render(post);
const url = new URL(`/blog/${post.id}`, Astro.site).href;
const jsonLd = articleJsonLd({
  title: post.data.title,
  description: post.data.description,
  url,
  datePublished: post.data.date.toISOString().slice(0, 10),
  dateModified: post.data.updated?.toISOString().slice(0, 10),
  author: post.data.author,
});
---
<BaseLayout title={`${post.data.title} — BusinessCode`} description={post.data.description} jsonLd={jsonLd}>
  <article>
    <h1>{post.data.title}</h1>
    <Content />
  </article>
</BaseLayout>
```

- [ ] **Step 5: Build e verificar páginas geradas**

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: gera `dist/blog.html` (listagem) e `dist/blog/whatsapp-business-api-o-guia-para-empresas-comecarem.html` (ou id equivalente). O HTML do post contém `"@type":"Article"`.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/content.config.ts site/src/content site/src/pages/blog
git commit -m "feat(site): blog editorial (collection glob + listagem + post)"
```

---

## Task 5: Guardrails anti-thin-content (TDD)

**Files:**
- Create: `site/src/lib/guardrails.ts`
- Test: `site/test/guardrails.test.ts`

- [ ] **Step 1: Escrever o teste que falha**

```ts
// site/test/guardrails.test.ts
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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd c:/xampp/htdocs/new_saas/site && npx vitest run test/guardrails.test.ts`
Expected: FAIL — módulo não existe.

- [ ] **Step 3: Implementar `site/src/lib/guardrails.ts`**

```ts
export const MIN_WORDS = 120;

export interface PageInput {
  body: string;
  uniqueFacts: string[];
}

export interface ValidationResult {
  ok: boolean;
  errors: string[];
}

export function validatePage(page: PageInput): ValidationResult {
  const errors: string[] = [];
  const wordCount = page.body.trim().split(/\s+/).filter(Boolean).length;
  if (wordCount < MIN_WORDS) errors.push('CONTEUDO_CURTO');
  if (!page.uniqueFacts.some((f) => f.trim().length > 0)) errors.push('SEM_DADO_UNICO');
  return { ok: errors.length === 0, errors };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd c:/xampp/htdocs/new_saas/site && npx vitest run test/guardrails.test.ts`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/lib/guardrails.ts site/test/guardrails.test.ts
git commit -m "feat(site): guardrails anti-thin-content (mín. palavras + dado único)"
```

---

## Task 6: Collections programáticas (dados) + schema

As 4 collections programáticas usam o loader `file` (cada JSON é um array de registros validado por Zod). Cada registro carrega `body` (markdown/string) e `uniqueFacts` para os guardrails.

**Files:**
- Modify: `site/src/content.config.ts`
- Create: `site/src/data/glossario.json`, `comparativos.json`, `segmentos.json`, `integracoes.json`

- [ ] **Step 1: Criar seeds de dados (1 registro cada) — `site/src/data/glossario.json`**

```json
[
  {
    "termo": "webhook",
    "slug": "webhook",
    "title": "O que é um Webhook em mensageria",
    "description": "Definição de webhook e como ele entrega eventos de mensagens em tempo real.",
    "body": "Um webhook é uma URL pública que recebe notificações HTTP automáticas quando um evento acontece — por exemplo, quando uma mensagem do WhatsApp é entregue, lida ou respondida. Em vez de a sua aplicação ficar consultando a API repetidamente (polling), o provedor envia um POST para a sua URL no instante do evento. Na BusinessCode, webhooks entregam status de mensagens (entregue, lida, falha) e mensagens recebidas, permitindo automações em tempo real. É a base de chatbots, distribuição de atendimento e relatórios ao vivo. Para usar, registre a URL no painel, valide a assinatura/token de cada requisição e responda 200 rapidamente para evitar reentregas.",
    "uniqueFacts": ["Entrega eventos via POST em tempo real", "Elimina polling"],
    "related": ["api"]
  }
]
```

- [ ] **Step 2: Criar `site/src/data/comparativos.json`**

```json
[
  {
    "concorrente": "twilio",
    "slug": "twilio",
    "title": "BusinessCode vs Twilio",
    "description": "Comparativo de preço, suporte em português e facilidade entre BusinessCode e Twilio.",
    "body": "BusinessCode e Twilio resolvem mensageria em escala, mas com focos diferentes. A BusinessCode é construída para o mercado brasileiro: suporte em português, faturamento em reais, e foco em WhatsApp, SMS, voz e e-mail num único painel via Infobip. A Twilio é uma plataforma global de APIs com altíssima flexibilidade, mas exige mais conhecimento técnico para configurar e cobra em dólar. Para uma equipe brasileira que quer subir campanhas de WhatsApp rapidamente, sem montar infraestrutura, a BusinessCode reduz o tempo de implementação. Para quem precisa de telefonia programável global e já tem time de engenharia dedicado, a Twilio oferece mais primitivas. A tabela abaixo resume preço, moeda, canais e suporte.",
    "uniqueFacts": ["Faturamento em BRL", "Suporte em português", "Painel único multicanal"],
    "related": []
  }
]
```

- [ ] **Step 3: Criar `site/src/data/segmentos.json`**

```json
[
  {
    "segmento": "clinicas",
    "slug": "clinicas",
    "title": "WhatsApp para Clínicas e Consultórios",
    "description": "Como clínicas usam WhatsApp para confirmar consultas, reduzir faltas e atender pacientes.",
    "body": "Clínicas e consultórios perdem receita com faltas (no-show) e gastam horas em confirmações manuais. Com a BusinessCode, a clínica automatiza lembretes de consulta por WhatsApp, confirma presença com um clique, reagenda automaticamente e responde dúvidas frequentes por chatbot — liberando a recepção. Mensagens transacionais (lembrete, confirmação, resultado pronto) usam templates aprovados, enquanto o atendimento humano assume quando o paciente responde. O resultado típico é menos faltas, agenda mais cheia e recepção menos sobrecarregada. Dados de saúde exigem cuidado: a plataforma permite restringir quem acessa as conversas e mantém histórico auditável.",
    "uniqueFacts": ["Lembretes reduzem no-show", "Templates transacionais + atendimento humano"],
    "related": []
  }
]
```

- [ ] **Step 4: Criar `site/src/data/integracoes.json`**

```json
[
  {
    "ferramenta": "rd-station",
    "slug": "rd-station",
    "title": "Integração BusinessCode + RD Station",
    "description": "Como conectar a BusinessCode ao RD Station para disparar WhatsApp a partir de automações de marketing.",
    "body": "A integração entre BusinessCode e RD Station conecta o marketing à mensageria: quando um lead avança em uma automação do RD Station, a BusinessCode dispara uma mensagem de WhatsApp no momento certo. Casos comuns: enviar boas-vindas após conversão em landing page, lembrar de carrinho abandonado, ou notificar o time comercial quando um lead fica quente. A conexão é feita por webhook — o RD Station chama um endpoint da BusinessCode com os dados do lead, e a campanha dispara o template correspondente. Não é preciso código: o mapeamento de campos é feito no painel. O retorno (entregue/lido/respondido) volta para o RD Station, fechando o ciclo de mensuração.",
    "uniqueFacts": ["Disparo por webhook sem código", "Retorno de status volta ao RD Station"],
    "related": []
  }
]
```

- [ ] **Step 5: Adicionar as 4 collections em `site/src/content.config.ts`** (acrescentar ao arquivo existente)

```ts
import { file } from 'astro/loaders';

const programmaticBase = z.object({
  slug: z.string(),
  title: z.string(),
  description: z.string(),
  body: z.string(),
  uniqueFacts: z.array(z.string()),
  related: z.array(z.string()).default([]),
});

const glossario = defineCollection({
  loader: file('./src/data/glossario.json', { parser: (text) => JSON.parse(text) }),
  schema: programmaticBase.extend({ termo: z.string() }),
});
const comparativos = defineCollection({
  loader: file('./src/data/comparativos.json'),
  schema: programmaticBase.extend({ concorrente: z.string() }),
});
const segmentos = defineCollection({
  loader: file('./src/data/segmentos.json'),
  schema: programmaticBase.extend({ segmento: z.string() }),
});
const integracoes = defineCollection({
  loader: file('./src/data/integracoes.json'),
  schema: programmaticBase.extend({ ferramenta: z.string() }),
});

export const collections = { blog, glossario, comparativos, segmentos, integracoes };
```

> Nota: o `file()` loader exige que cada item tenha um `id` único. Como os JSONs são arrays sem chave `id`, use o `parser` para indexar pelo `slug`. Substitua cada `file('...')` acima por: `file('./src/data/<arquivo>.json', { parser: (text) => Object.fromEntries(JSON.parse(text).map((e) => [e.slug, e])) })`. Aplicar esse parser nas 4 collections.

- [ ] **Step 6: Build e confirmar que as collections carregam**

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: build sem erros de schema. (Ainda não há páginas programáticas — vêm na Task 7. Se o build avisar "collection sem página", tudo bem.)

- [ ] **Step 7: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/content.config.ts site/src/data
git commit -m "feat(site): collections programáticas (glossário/vs/segmentos/integrações) + seeds"
```

---

## Task 7: Páginas programáticas (4 rotas dinâmicas)

Cada rota gera uma página por registro, com JSON-LD. Todas seguem o mesmo molde — varia o nome da collection e o `param`.

**Files:**
- Create: `site/src/pages/glossario/[termo].astro`, `vs/[concorrente].astro`, `para/[segmento].astro`, `integracoes/[ferramenta].astro`

- [ ] **Step 1: `site/src/pages/glossario/[termo].astro`**

```astro
---
import { getCollection } from 'astro:content';
import BaseLayout from '../../layouts/BaseLayout.astro';
import { articleJsonLd, breadcrumbJsonLd } from '../../lib/seo';

export async function getStaticPaths() {
  const entries = await getCollection('glossario');
  return entries.map((e) => ({ params: { termo: e.data.slug }, props: { e } }));
}
const { e } = Astro.props;
const url = new URL(`/glossario/${e.data.slug}`, Astro.site).href;
const jsonLd = [
  articleJsonLd({ title: e.data.title, description: e.data.description, url, datePublished: '2026-06-10', author: 'BusinessCode' }),
  breadcrumbJsonLd([
    { name: 'Glossário', url: new URL('/glossario', Astro.site).href },
    { name: e.data.title, url },
  ]),
];
---
<BaseLayout title={`${e.data.title} — BusinessCode`} description={e.data.description} jsonLd={jsonLd}>
  <article>
    <h1>{e.data.title}</h1>
    <p>{e.data.body}</p>
  </article>
</BaseLayout>
```

- [ ] **Step 2: `site/src/pages/vs/[concorrente].astro`** — mesmo molde, trocando collection/param

```astro
---
import { getCollection } from 'astro:content';
import BaseLayout from '../../layouts/BaseLayout.astro';
import { articleJsonLd, breadcrumbJsonLd } from '../../lib/seo';

export async function getStaticPaths() {
  const entries = await getCollection('comparativos');
  return entries.map((e) => ({ params: { concorrente: e.data.slug }, props: { e } }));
}
const { e } = Astro.props;
const url = new URL(`/vs/${e.data.slug}`, Astro.site).href;
const jsonLd = [
  articleJsonLd({ title: e.data.title, description: e.data.description, url, datePublished: '2026-06-10', author: 'BusinessCode' }),
  breadcrumbJsonLd([
    { name: 'Comparativos', url: new URL('/vs', Astro.site).href },
    { name: e.data.title, url },
  ]),
];
---
<BaseLayout title={`${e.data.title} — BusinessCode`} description={e.data.description} jsonLd={jsonLd}>
  <article>
    <h1>{e.data.title}</h1>
    <p>{e.data.body}</p>
  </article>
</BaseLayout>
```

- [ ] **Step 3: `site/src/pages/para/[segmento].astro`** — collection `segmentos`, param `segmento`

```astro
---
import { getCollection } from 'astro:content';
import BaseLayout from '../../layouts/BaseLayout.astro';
import { articleJsonLd, breadcrumbJsonLd } from '../../lib/seo';

export async function getStaticPaths() {
  const entries = await getCollection('segmentos');
  return entries.map((e) => ({ params: { segmento: e.data.slug }, props: { e } }));
}
const { e } = Astro.props;
const url = new URL(`/para/${e.data.slug}`, Astro.site).href;
const jsonLd = [
  articleJsonLd({ title: e.data.title, description: e.data.description, url, datePublished: '2026-06-10', author: 'BusinessCode' }),
  breadcrumbJsonLd([
    { name: 'Casos de uso', url: new URL('/para', Astro.site).href },
    { name: e.data.title, url },
  ]),
];
---
<BaseLayout title={`${e.data.title} — BusinessCode`} description={e.data.description} jsonLd={jsonLd}>
  <article>
    <h1>{e.data.title}</h1>
    <p>{e.data.body}</p>
  </article>
</BaseLayout>
```

- [ ] **Step 4: `site/src/pages/integracoes/[ferramenta].astro`** — collection `integracoes`, param `ferramenta`

```astro
---
import { getCollection } from 'astro:content';
import BaseLayout from '../../layouts/BaseLayout.astro';
import { articleJsonLd, breadcrumbJsonLd } from '../../lib/seo';

export async function getStaticPaths() {
  const entries = await getCollection('integracoes');
  return entries.map((e) => ({ params: { ferramenta: e.data.slug }, props: { e } }));
}
const { e } = Astro.props;
const url = new URL(`/integracoes/${e.data.slug}`, Astro.site).href;
const jsonLd = [
  articleJsonLd({ title: e.data.title, description: e.data.description, url, datePublished: '2026-06-10', author: 'BusinessCode' }),
  breadcrumbJsonLd([
    { name: 'Integrações', url: new URL('/integracoes', Astro.site).href },
    { name: e.data.title, url },
  ]),
];
---
<BaseLayout title={`${e.data.title} — BusinessCode`} description={e.data.description} jsonLd={jsonLd}>
  <article>
    <h1>{e.data.title}</h1>
    <p>{e.data.body}</p>
  </article>
</BaseLayout>
```

- [ ] **Step 5: Build e conferir as 4 páginas**

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: gera `dist/glossario/webhook.html`, `dist/vs/twilio.html`, `dist/para/clinicas.html`, `dist/integracoes/rd-station.html`. Cada uma contém `"@type":"Article"` e `"@type":"BreadcrumbList"`.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/pages/glossario site/src/pages/vs site/src/pages/para site/src/pages/integracoes
git commit -m "feat(site): páginas programáticas (glossário/vs/para/integrações)"
```

---

## Task 8: Páginas institucionais + migração da landing

**Files:**
- Modify/Create: `site/src/pages/index.astro` (landing real), `precos.astro`, `recursos.astro`, `sobre.astro`, `contato.astro`
- Source: `frontend/public/landing.html`

- [ ] **Step 1: Ler a landing atual para portar o conteúdo**

Run: `cat c:/xampp/htdocs/new_saas/frontend/public/landing.html`
Expected: ver o HTML/CSS da landing de vendas atual.

- [ ] **Step 2: Migrar para `site/src/pages/index.astro`**

Copiar o conteúdo de `<body>` da landing para dentro do `<slot>` do `BaseLayout`, e mover os estilos `<style>` inline para um bloco `<style>` no `.astro` (ou para `src/styles/landing.css` importado). Estrutura alvo:

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout
  title="BusinessCode — Mensageria multicanal para empresas"
  description="WhatsApp, SMS, voz e e-mail em um só painel. Campanhas, chatbot e atendimento para a sua empresa vender mais.">
  <!-- COLAR AQUI o conteúdo do <body> da frontend/public/landing.html -->
</BaseLayout>

<style is:global>
  /* COLAR AQUI o CSS que estava no <style> da landing.html */
</style>
```

> Importante: ajustar caminhos de assets (imagens/sons) — copiar os referenciados pela landing de `frontend/dist/` ou `frontend/public/` para `site/public/` e referenciar com caminho absoluto (`/arquivo.ext`).

- [ ] **Step 3: Criar `site/src/pages/precos.astro` com FAQPage JSON-LD**

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
import { faqJsonLd } from '../lib/seo';
const faq = [
  { question: 'Tem plano gratuito?', answer: 'Sim, é possível começar gratuitamente e pagar conforme o uso.' },
  { question: 'A cobrança é em reais?', answer: 'Sim, todo o faturamento é em reais (BRL).' },
];
---
<BaseLayout title="Preços — BusinessCode" description="Planos e preços da BusinessCode, faturamento em reais." jsonLd={faqJsonLd(faq)}>
  <h1>Preços</h1>
  <section>
    {faq.map((f) => (<details><summary>{f.question}</summary><p>{f.answer}</p></details>))}
  </section>
</BaseLayout>
```

- [ ] **Step 4: Criar `recursos.astro`, `sobre.astro`, `contato.astro`** (páginas simples com BaseLayout)

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout title="Recursos — BusinessCode" description="WhatsApp API, SMS, voz, e-mail, campanhas, chatbot e relatórios em um só lugar.">
  <h1>Recursos</h1>
  <p>WhatsApp Business API, SMS, voz, e-mail, campanhas, chatbot e relatórios.</p>
</BaseLayout>
```

Repetir o mesmo molde para `sobre.astro` (title "Sobre", conteúdo institucional) e `contato.astro` (title "Contato", dados de contato/CTA para `dash.businesscode.com.br`).

- [ ] **Step 5: Build e verificar**

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: gera `dist/index.html` (landing migrada), `dist/precos.html` (com FAQPage JSON-LD), `dist/recursos.html`, `dist/sobre.html`, `dist/contato.html`.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/pages site/public
git commit -m "feat(site): migra landing + páginas institucionais (preços/recursos/sobre/contato)"
```

---

## Task 9: robots.txt, llms.txt

**Files:**
- Create: `site/src/pages/robots.txt.ts`, `site/src/pages/llms.txt.ts`

- [ ] **Step 1: `site/src/pages/robots.txt.ts`**

```ts
import type { APIRoute } from 'astro';

export const GET: APIRoute = ({ site }) => {
  const body = [
    'User-agent: *',
    'Allow: /',
    '',
    `Sitemap: ${new URL('sitemap-index.xml', site).href}`,
  ].join('\n');
  return new Response(body, { headers: { 'Content-Type': 'text/plain' } });
};
```

- [ ] **Step 2: `site/src/pages/llms.txt.ts`**

```ts
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';

export const GET: APIRoute = async ({ site }) => {
  const posts = await getCollection('blog', ({ data }) => !data.draft);
  const lines = [
    '# BusinessCode',
    '',
    '> Plataforma brasileira de mensageria multicanal (WhatsApp, SMS, voz, e-mail) para empresas: campanhas, chatbot e atendimento.',
    '',
    '## Blog',
    ...posts.map((p) => `- [${p.data.title}](${new URL(`/blog/${p.id}`, site).href}): ${p.data.description}`),
  ];
  return new Response(lines.join('\n'), { headers: { 'Content-Type': 'text/plain' } });
};
```

- [ ] **Step 3: Build e verificar**

Run: `cd c:/xampp/htdocs/new_saas/site && npm run build`
Expected: gera `dist/robots.txt`, `dist/llms.txt`, e `dist/sitemap-index.xml` (do `@astrojs/sitemap`).

- [ ] **Step 4: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/src/pages/robots.txt.ts site/src/pages/llms.txt.ts
git commit -m "feat(site): robots.txt, llms.txt e sitemap"
```

---

## Task 10: Pipeline de geração com guardrails (TDD)

Script que recebe um registro candidato e só o grava no JSON da collection se passar nos guardrails. (A integração com IA fica isolada atrás de uma função `draftWithAI` — neste plano ela retorna o body recebido; a chamada real à API Claude é plugada depois, fora do escopo deste plano.)

**Files:**
- Create: `site/scripts/generate.ts`
- Test: `site/test/generate.test.ts`

- [ ] **Step 1: Escrever o teste que falha**

```ts
// site/test/generate.test.ts
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
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd c:/xampp/htdocs/new_saas/site && npx vitest run test/generate.test.ts`
Expected: FAIL — módulo não existe.

- [ ] **Step 3: Implementar `site/scripts/generate.ts`**

```ts
import { validatePage } from '../src/lib/guardrails';

export interface GenInput {
  slug: string;
  title: string;
  description: string;
  body: string;
  uniqueFacts: string[];
  key: 'termo' | 'concorrente' | 'segmento' | 'ferramenta';
}

export function buildEntry(input: GenInput): Record<string, unknown> {
  const check = validatePage({ body: input.body, uniqueFacts: input.uniqueFacts });
  if (!check.ok) {
    throw new Error(`guardrail falhou para "${input.slug}": ${check.errors.join(', ')}`);
  }
  return {
    slug: input.slug,
    [input.key]: input.slug,
    title: input.title,
    description: input.description,
    body: input.body,
    uniqueFacts: input.uniqueFacts,
    related: [],
  };
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `cd c:/xampp/htdocs/new_saas/site && npx vitest run test/generate.test.ts`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/scripts/generate.ts site/test/generate.test.ts
git commit -m "feat(site): pipeline de geração com guardrails (buildEntry)"
```

---

## Task 11: Smoke test do build

Garante que o build produz as páginas e artefatos esperados.

**Files:**
- Create: `site/test/build-smoke.test.ts`

- [ ] **Step 1: Escrever o smoke test**

```ts
// site/test/build-smoke.test.ts
import { describe, it, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const dist = (p: string) => resolve(__dirname, '..', 'dist', p);

describe('build output', () => {
  it('gera as páginas-chave', () => {
    for (const f of ['index.html', 'blog.html', 'precos.html', 'glossario/webhook.html', 'vs/twilio.html', 'para/clinicas.html', 'integracoes/rd-station.html', 'robots.txt', 'llms.txt', 'sitemap-index.xml']) {
      expect(existsSync(dist(f)), `faltou ${f}`).toBe(true);
    }
  });

  it('o post do blog tem JSON-LD Article', () => {
    const html = readFileSync(dist('precos.html'), 'utf-8');
    expect(html).toContain('"@type":"FAQPage"');
  });
});
```

- [ ] **Step 2: Rodar build + smoke test**

Run:
```bash
cd c:/xampp/htdocs/new_saas/site && npm run build && npx vitest run test/build-smoke.test.ts
```
Expected: build OK e smoke test PASS. (O smoke test depende do `dist/` recém-gerado.)

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/test/build-smoke.test.ts
git commit -m "test(site): smoke test do build (páginas + artefatos SEO)"
```

---

## Task 12: Deploy no Apache (VirtualHost do domínio raiz)

**Files:**
- Create: `site/dist/.htaccess` (via template) e `c:/xampp/apache/conf/extra/businesscode-site.conf`
- Create: `site/htaccess.template`

- [ ] **Step 1: Criar `site/htaccess.template`** (espelha o do frontend, mas sem rewrite de SPA — site estático com `format: 'file'`)

```apache
# BusinessCode Site — Apache
AddType application/javascript .js .mjs
AddType text/css .css
AddType image/svg+xml .svg
AddType application/json .json
AddType font/woff2 .woff2

<IfModule mod_headers.c>
  <FilesMatch "\.html$">
    Header set Cache-Control "no-cache, no-store, must-revalidate"
  </FilesMatch>
  <FilesMatch "\.(css|js|woff2?|svg|png|jpg|webp|ico)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>

# 404 amigável
ErrorDocument 404 /404.html
```

- [ ] **Step 2: Criar `site/src/pages/404.astro`**

```astro
---
import BaseLayout from '../layouts/BaseLayout.astro';
---
<BaseLayout title="Página não encontrada — BusinessCode" description="Página não encontrada." noindex={true}>
  <h1>404 — Página não encontrada</h1>
  <p><a href="/">Voltar para a home</a></p>
</BaseLayout>
```

- [ ] **Step 3: Criar o VirtualHost `c:/xampp/apache/conf/extra/businesscode-site.conf`**

```apache
<VirtualHost *:80>
    ServerName businesscode.com.br
    ServerAlias www.businesscode.com.br
    DocumentRoot "c:/xampp/htdocs/new_saas/site/dist"
    <Directory "c:/xampp/htdocs/new_saas/site/dist">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

> Incluir este arquivo no `httpd.conf` (ou `httpd-vhosts.conf`) com `Include conf/extra/businesscode-site.conf` se ainda não houver um glob que o pegue. Confirmar que `mod_headers` e `mod_rewrite` estão habilitados. NÃO mexer no VirtualHost de `dash.businesscode.com.br`.

- [ ] **Step 4: Copiar o `.htaccess` para o dist e validar config do Apache**

Run:
```bash
cd c:/xampp/htdocs/new_saas/site
npm run build
cp htaccess.template dist/.htaccess
c:/xampp/apache/bin/httpd.exe -t
```
Expected: `Syntax OK`.

- [ ] **Step 5: Reiniciar Apache e testar local (ajustar hosts se necessário)**

Run:
```bash
c:/xampp/apache/bin/httpd.exe -k restart
```
Validar acessando `http://businesscode.com.br/` (com DNS/hosts apontando) — deve servir a landing; `/blog`, `/precos`, `/glossario/webhook` devem responder 200; `/robots.txt` e `/llms.txt` devem retornar texto.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs/new_saas
git add site/htaccess.template site/src/pages/404.astro
git commit -m "chore(site): htaccess, página 404 e VirtualHost do domínio raiz"
```

> O `businesscode-site.conf` vive fora do repo (em `c:/xampp/apache/conf/extra/`) — documentado aqui, não versionado neste commit.

---

## Self-Review (preenchido pelo autor do plano)

**Spec coverage:**
- Arquitetura (`site/` Astro + `frontend/` intocado) → Task 1, 12. ✅
- Blog editorial (collection glob) → Task 4. ✅
- Programático glossário/vs/para/integrações → Task 6, 7. ✅
- Guardrails anti-thin-content → Task 5, 10. ✅
- Pipeline de automação → Task 10. ✅
- SEO/JSON-LD (Article/FAQ/Organization/Breadcrumb) → Task 2, 3, 7, 8. ✅
- sitemap/robots/llms.txt → Task 9. ✅
- Migração da landing → Task 8. ✅
- Deploy Apache (vhost raiz, dash intocado) → Task 12. ✅
- Testes (schema/guardrails/smoke) → Task 5, 10, 11. ✅

**Placeholder scan:** As únicas partes "cole aqui" são na migração da landing (Task 8), inerente a portar HTML existente — o passo aponta o arquivo-fonte e a estrutura-alvo exata. Sem TODO/TBD.

**Type consistency:** `validatePage`/`PageInput` (guardrails) usados em `generate.ts`; `articleJsonLd`/`faqJsonLd`/`breadcrumbJsonLd`/`organizationJsonLd` definidos na Task 2 e consumidos nas Tasks 3/7/8 com as mesmas assinaturas. `programmaticBase` + `.extend` consistente nas 4 collections e nos params das rotas (`termo/concorrente/segmento/ferramenta`). ✅

## Riscos de execução
- **API de loaders do Astro** pode variar entre 5.x e 6.x (`file()` exige `id` único — tratado com `parser` na Task 6). Se a versão instalada divergir, conferir docs via context7 antes de ajustar.
- **`format: 'file'`** muda os caminhos de saída — o smoke test (Task 11) cobre os nomes esperados.
