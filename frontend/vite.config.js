import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://app:8000',
        changeOrigin: true,
      },
      // Hochgeladene Geraetebilder liegen hinter dem Backend
      '/storage': {
        target: 'http://app:8000',
        changeOrigin: true,
      },
    },
  },
})
