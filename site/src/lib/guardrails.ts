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
