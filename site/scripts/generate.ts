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
