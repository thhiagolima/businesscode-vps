import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import mdx from '@astrojs/mdx';

export default defineConfig({
  site: 'https://businesscode.com.br',
  trailingSlash: 'never',
  integrations: [mdx(), sitemap()],
  build: {
    format: 'file',
  },
});
