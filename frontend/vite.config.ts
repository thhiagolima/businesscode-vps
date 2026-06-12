/// <reference types="vitest" />
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  resolve: {
    alias: {
      // Resolução absoluta do diretório src — funciona tanto no Vite (dev/build)
      // quanto no Vitest (que não entende o atalho '/src').
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.{test,spec}.{ts,js}'],
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor-vue': ['vue', 'vue-router', 'pinia'],
          'vendor-charts': ['apexcharts', 'vue3-apexcharts'],
          'vendor-tabler': ['@tabler/core'],
        },
      },
    },
  },
  // Gera app.html em vez de index.html (landing page é o index.html)
  experimental: {
    renderBuiltUrl(filename) {
      return '/' + filename
    },
  },
})
