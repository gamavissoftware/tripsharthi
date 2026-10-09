import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5917,
    strictPort: true,
    proxy: {
      // Forward all /api calls to the TravelPilot CI4 backend
      // (php spark serve --port 8731 from backend/).
      '/api': {
        target: 'http://localhost:8731',
        changeOrigin: true,
      },
    },
  },
})
