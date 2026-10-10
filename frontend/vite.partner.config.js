import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// The referral-partner portal (partners.tripsarthi.com): its own entry (partner.html), build output and session key.
// Dev:   npm run dev:partner     -> http://localhost:5919     Build: npm run build:partner -> dist-partner/
const partnerAtRoot = { name: 'partner-at-root', configureServer(server) { server.middlewares.use((req, _res, next) => { if (req.url === '/' || req.url.startsWith('/?')) req.url = '/partner.html'; next() }) } }

export default defineConfig({
  plugins: [react(), partnerAtRoot],
  define: { __PARTNER_APP__: 'true' },
  build: { outDir: 'dist-partner', emptyOutDir: true, rollupOptions: { input: 'partner.html' } },
  server: {
    port: 5919,
    strictPort: true,
    proxy: { '/api': { target: 'http://localhost:8731', changeOrigin: true } },
  },
})
