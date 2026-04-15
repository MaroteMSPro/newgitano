import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: 'app.html'
    }
  },
  server: {
    proxy: {
      '/api': {
        target: 'https://elgitano.luxom.com.ar',
        changeOrigin: true
      }
    }
  }
})
