import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))


// Modules reachable from main.jsx through static imports only (not through import()).
let eagerSet = null
function eager(id, meta) {
  if (!eagerSet) {
    eagerSet = new Set()
    const root = path.resolve(__dirname, 'src/main.jsx').split(path.sep).join('/')
    const queue = [root]
    while (queue.length) {
      const cur = queue.pop()
      if (eagerSet.has(cur)) continue
      eagerSet.add(cur)
      const info = meta.getModuleInfo(cur)
      if (info) for (const dep of info.importedIds) queue.push(dep)
    }
    eagerSet.delete(root) // main.jsx stays in the entry: it sets window.React before fotonic-pro.js runs
  }
  return eagerSet.has(id)
}

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
        manualChunks(id, meta) {
          if (/node_modules\/(react|react-dom|scheduler|react-router|react-router-dom|@tanstack[\\/]react-query)\//.test(id)) {
            return 'vendor'
          }
          // Everything the entry loads eagerly (components, contexts, utils, icons...) goes to ONE
          // hashed chunk, so the lazy pages import THAT chunk and never import fotonic-app.js itself.
          // If a page imports the entry by its bare name, the browser fetches it a second time under
          // a URL with no ?ver=, runs the whole app twice, and keeps the old copy in its cache and in
          // the host's CDN after an update (old chunk names, 404 on the first click: 1.6.8 demo).
          if (eager(id, meta)) return 'app'
        },
      }
    }
  },
  server: {
    proxy: { '/wp-json': { target: 'http://fotonic.local', changeOrigin: true } }
  }
})
