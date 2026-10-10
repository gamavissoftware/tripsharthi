import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The separate platform-admin app (admin.tripsarthi.com): its own entry (admin.html), own build output, own session key.
// Dev:   npm run dev:admin     -> http://localhost:5918/admin.html     Build: npm run build:admin -> dist-admin/
// In dev, serve the admin entry at "/" (nginx does the same in production).
const adminAtRoot = { name: 'admin-at-root', configureServer(server) { server.middlewares.use((req, _res, next) => { if (req.url === '/' || req.url.startsWith('/?')) req.url = '/admin.html'; next() }) } }

export default defineConfig({
  plugins: [react(), adminAtRoot],
  define: { __ADMIN_APP__: 'true' },
  build: { outDir: 'dist-admin', emptyOutDir: true, rollupOptions: { input: 'admin.html' } },
  server: {
    port: 5918,
    strictPort: true,
    proxy: { '/api': { target: 'http://localhost:8731', changeOrigin: true } },
  },
})
