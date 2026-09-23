/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// En dev, le SPA (localhost:5173) proxifie l'API Laravel (127.0.0.1:8000) :
// même origine pour le navigateur, donc cookies de session Sanctum sans CORS.
// Cible du proxy configurable (API_PROXY_TARGET) si le port 8000 est déjà pris sur la machine.
const apiTarget = process.env.API_PROXY_TARGET ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: apiTarget, changeOrigin: false },
      '/sanctum': { target: apiTarget, changeOrigin: false },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    // Délai généreux : la suite tourne aussi sur des machines chargées.
    testTimeout: 30_000,
  },
})
