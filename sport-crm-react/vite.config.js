import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
// Backend для локальної розробки.
// Перед фінальним тестуванням перед запуском — замінити на продакшн-домен.
const DEV_BACKEND = 'https://crm.gyms.pp.ua';

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: DEV_BACKEND,
        changeOrigin: true,
        secure: true,
        // Cookie сесії має зберігатись для localhost, а не для реального домену
        cookieDomainRewrite: 'localhost',
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.js',
    globals: true,
  },
})
