import { defineCollection, z } from 'astro:content';
import { glob, file } from 'astro/loaders';
import { validatePage } from './lib/guardrails';

function withGuardrail<T extends z.ZodTypeAny>(schema: T) {
  return schema.superRefine((data: any, ctx) => {
    const r = validatePage({ body: data.body, uniqueFacts: data.uniqueFacts });
    if (!r.ok) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: `guardrail anti-thin-content: ${r.errors.join(', ')}` });
    }
  });
}

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

const programmaticBase = z.object({
  slug: z.string(),
  title: z.string(),
  description: z.string(),
  body: z.string(),
  uniqueFacts: z.array(z.string()),
  related: z.array(z.string()).default([]),
});

const glossario = defineCollection({
  loader: file('./src/data/glossario.json', { parser: (text) => Object.fromEntries(JSON.parse(text).map((e) => [e.slug, e])) }),
  schema: withGuardrail(programmaticBase.extend({ termo: z.string() })),
});
const comparativos = defineCollection({
  loader: file('./src/data/comparativos.json', { parser: (text) => Object.fromEntries(JSON.parse(text).map((e) => [e.slug, e])) }),
  schema: withGuardrail(programmaticBase.extend({ concorrente: z.string() })),
});
const segmentos = defineCollection({
  loader: file('./src/data/segmentos.json', { parser: (text) => Object.fromEntries(JSON.parse(text).map((e) => [e.slug, e])) }),
  schema: withGuardrail(programmaticBase.extend({ segmento: z.string() })),
});
const integracoes = defineCollection({
  loader: file('./src/data/integracoes.json', { parser: (text) => Object.fromEntries(JSON.parse(text).map((e) => [e.slug, e])) }),
  schema: withGuardrail(programmaticBase.extend({ ferramenta: z.string() })),
});

export const collections = { blog, glossario, comparativos, segmentos, integracoes };
