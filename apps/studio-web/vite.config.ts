import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// The API is proxied so the SPA and the API share one origin (Sanctum cookie auth, no CORS).
const api = process.env.STUDIO_API_URL ?? 'http://127.0.0.1:8000';
const proxy = {
  '/api': { target: api, changeOrigin: false },
  '/sanctum': { target: api, changeOrigin: false },
};

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: { port: 5173, strictPort: true, proxy },
  preview: { port: 4173, strictPort: true, proxy },
  build: { sourcemap: true, chunkSizeWarningLimit: 900 },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.{ts,tsx}'],
    setupFiles: ['./src/test-setup.ts'],
  },
});
