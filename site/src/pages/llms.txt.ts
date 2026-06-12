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
