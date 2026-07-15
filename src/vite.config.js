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
        // Pin the packages fotonic-pro.js externalizes (see its vite.config.js `external`/`globals`)
        // into one stable chunk. Without this, Rollup's automatic chunking can duplicate a module
        // like @tanstack/react-query across chunks whenever the async-import graph changes elsewhere
        // (e.g. adding an unrelated React.lazy() boundary) — a duplicate copy breaks the singleton
        // React context fotonic-pro.js's components rely on via window.FotonicVendors, throwing
        // "No QueryClient set" the moment a Pro-injected component mounts.
        manualChunks(id) {
          if (/node_modules\/(react|react-dom|scheduler|react-router|react-router-dom|@tanstack[\\/]react-query)\//.test(id)) {
            return 'vendor'
          }
        },
      }
    }
  },
  server: {
    proxy: { '/wp-json': { target: 'http://fotonic.local', changeOrigin: true } }
  }
})
