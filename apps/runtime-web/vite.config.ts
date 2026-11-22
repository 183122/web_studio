import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Built into the API's public/runtime directory and referenced through .vite/manifest.json
// (RuntimeAssets.php). The HTML shell is produced by the API per request (SEO + release data).
export default defineConfig({
  plugins: [react()],
  base: '/runtime/',
  build: {
    outDir: fileURLToPath(new URL('../platform-api/public/runtime', import.meta.url)),
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    rollupOptions: { input: 'src/main.tsx' },
  },
  test: { environment: 'jsdom', include: ['src/**/*.test.{ts,tsx}'], globals: true },
});
