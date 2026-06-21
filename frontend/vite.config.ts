import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    port: 9100,
    proxy: {
      // Forward API calls to the Laravel backend (M1) during dev.
      '/api': 'http://localhost:9000',
    },
  },
})
