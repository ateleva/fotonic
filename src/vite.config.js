import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: path.resolve(__dirname, '../dist'),
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'fotonic-app.js',
        chunkFileNames: 'fotonic-chunk-[name]-[hash].js',
        assetFileNames: (info) => info.name?.endsWith('.css') ? 'fotonic-app.css' : (info.name ?? 'asset'),
      }
    }
  },
  server: {
    proxy: { '/wp-json': { target: 'http://fotonic.local', changeOrigin: true } }
  }
})
