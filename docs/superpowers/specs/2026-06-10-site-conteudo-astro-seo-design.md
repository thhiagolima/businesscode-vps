# Site de Conteúdo (Astro) — SEO + Citação por IA

**Data:** 2026-06-10
**Status:** Aprovado para planejamento
**Autor:** BusinessCode

## Problema

O front público hoje é **uma única página de vendas** (`frontend/public/landing.html`). Isso limita:

- **SEO**: pouquíssimas URLs indexáveis, nenhum conteúdo de cauda longa.
- **Indexação**: nada além da home pra o Google rankear.
- **Citação por agentes de IA**: faltam páginas factuais, estruturadas e crawláveis que LLMs possam citar.

Objetivo: criar um **site de marketing + conteúdo em escala**, em código, com SSG, que maximize SEO e citação por IA, alimentado por um pipeline de automação de conteúdo.

## Decisões já tomadas

| Tema | Decisão |
|------|---------|
| Plataforma | **Astro** (SSG estático). WordPress descartado (rema contra automação, peso de manutenção/segurança, performance dinâmica, fragmenta o stack Laravel+Vue). |
| Domínio | `businesscode.com.br` (raiz) = site novo de marketing+conteúdo. `dash.businesscode.com.br` = app Vue atual, **intocado**. |
| Autoria | Equipe técnica, frequente, com **automação** de conteúdo. |
| Tipos de conteúdo | Blog editorial + SEO programático em massa + FAQ + páginas institucionais. |
| CMS headless | Descartado por ora (YAGNI). Porta aberta: WordPress headless no futuro se entrar redator não-técnico. |

## Arquitetura

Projeto Astro novo, isolado do app Vue, no mesmo repositório git.

```
businesscode.com.br        → Astro (estático)  — NOVO
dash.businesscode.com.br   → Vue SPA (atual)   — sem mudança
```

Estrutura:

```
frontend/                  # app Vue (atual, intocado)
site/                      # NOVO projeto Astro
  src/
    pages/
      index.astro          # landing (migrada da landing.html atual)
      precos.astro
      recursos.astro
      sobre.astro
      contato.astro
      blog/
        index.astro        # listagem
        [...slug].astro     # post individual
      glossario/[termo].astro
      vs/[concorrente].astro
      para/[segmento].astro
      integracoes/[ferramenta].astro
    content/
      blog/                # .md / .mdx (editorial)
      config.ts            # schemas Zod das collections
    data/                  # datasets das páginas programáticas (json)
    components/            # Hero, Card, FAQ, SEO, JsonLd, ...
    layouts/
  scripts/
    generate.ts            # pipeline de geração de conteúdo
  astro.config.mjs
  package.json
```

**Justificativa:** isola build/deps/deploy do app, mas mantém tudo num único git para que CI e automação fiquem centralizados.

## Modelo de conteúdo

Todo conteúdo é **dado versionado no repo**.

### Blog editorial
- Arquivos `.md`/`.mdx` em `site/src/content/blog/`.
- Schema Zod (Content Collections) validado em build:
  `title`, `description`, `date`, `updated?`, `author`, `tags[]`, `cover?`, `draft` (default `true`).
- Build **falha** se um campo obrigatório faltar.

### Programático (todas as variantes aprovadas)
Cada dataset em `site/src/data/*.json`; cada registro vira uma página via `getStaticPaths()`.

| Rota | Dataset | Conteúdo único por página |
|------|---------|---------------------------|
| `/glossario/[termo]` | `glossario.json` | Definição densa do termo de mensageria/WhatsApp + exemplos + termos relacionados. |
| `/vs/[concorrente]` | `comparativos.json` | Comparativo BusinessCode vs concorrente com tabela de dados reais. |
| `/para/[segmento]` | `segmentos.json` | Caso de uso por segmento/indústria com exemplos e dores específicas. |
| `/integracoes/[ferramenta]` | `integracoes.json` | Página por integração/ferramenta com passos e benefícios. |

## Pipeline de automação + guardrails

Fluxo: **fonte de dados → geração (script + IA) → arquivos no repo → build Astro → deploy**.

- `site/scripts/generate.ts`: lê fonte (planilha/DB/API), chama IA para redigir, grava `.md`/`.json`, roda validação.
- **Guardrails anti-thin-content** (essenciais para o programático não ser penalizado por Helpful Content / spam policies):
  1. Mínimo de palavras/dados por página — build falha se abaixo do limite.
  2. Cada página exige ≥1 dado único real (não apenas template com nome trocado).
  3. `draft: true` por padrão → revisão humana antes de publicar.
  4. Indexação em **lotes** — não publicar milhares de URLs de uma vez no sitemap.

## Camada de SEO + citação por IA

- **JSON-LD** por tipo: `Article`, `FAQPage`, `BreadcrumbList`, `Organization`, `Product`.
- `@astrojs/sitemap` (sitemap automático), `robots.txt`, **`llms.txt`** (para agentes de IA).
- Componente `<SEO>` central: meta tags, Open Graph, canonical por página.
- HTML semântico, hierarquia de headings correta, **resposta direta no topo** (formato citável por LLM).
- Performance: Astro islands — quase zero JS → Core Web Vitals verdes.

## Deploy (Apache / XAMPP / Windows)

- Build: `cd site && npm run build` → gera `site/dist/` (HTML estático).
- **VirtualHost novo** para `businesscode.com.br` apontando para `site/dist/`, com `.htaccess` espelhando o atual (MIME types, cache imutável de assets, no-cache no HTML).
- `dash.businesscode.com.br` continua apontando para `frontend/dist`. Sem conflito.
- Migrar `frontend/public/landing.html` para `site/src/pages/index.astro` (ganha a camada de SEO/JSON-LD).

## Testes

- Validação de schema das collections (build-time).
- Teste do script de geração (guardrails) com Vitest.
- Smoke test: build gera o número esperado de páginas + sitemap válido + JSON-LD presente.

## Fora de escopo (YAGNI)

- CMS headless (adicionável depois sem retrabalho).
- Internacionalização/multi-idioma.
- Comentários no blog.
- Migração do app Vue.

## Riscos

- **Thin content programático** → mitigado pelos guardrails acima.
- **Volume de indexação** → publicar em lotes, monitorar Search Console.
- **Qualidade do conteúdo gerado por IA** → gate de revisão humana (`draft`).
