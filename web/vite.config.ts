/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

/**
 * Dev server config.
 *
 * The proxy is not a convenience — it makes development match production. In
 * production nginx serves the SPA and both services from a single origin, so
 * the refresh cookie is same-site and no CORS preflight ever happens. Proxying
 * `/api` and `/realtime` here reproduces that exactly, which means the auth
 * flow is exercised in dev the same way it runs in prod rather than being
 * discovered to be broken at deploy time.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY ?? 'http://localhost:8000',
        changeOrigin: true,
      },
      '/realtime': {
        target: process.env.VITE_REALTIME_PROXY ?? 'http://localhost:3001',
        changeOrigin: true,
        rewrite: (path: string) => path.replace(/^\/realtime/, ''),
        // Server-Sent Events die instantly if anything in the chain buffers
        // them, so the dev proxy has to be told the same thing nginx is.
        configure: (proxy) => {
          proxy.on('proxyRes', (proxyRes) => {
            proxyRes.headers['cache-control'] = 'no-cache, no-transform';
            proxyRes.headers['x-accel-buffering'] = 'no';
          });
        },
      },
      '/ws': {
        target: process.env.VITE_REALTIME_PROXY ?? 'http://localhost:3001',
        ws: true,
        changeOrigin: true,
      },
    },
  },

  build: {
    outDir: 'dist',
    sourcemap: true,
    rollupOptions: {
      output: {
        /**
         * Split the heavy, rarely-changing libraries into their own chunks.
         * Charting is a couple of hundred kilobytes that the incident list
         * never needs, and keeping it out of the main bundle is the difference
         * between a dashboard that loads during an outage and one that does not.
         */
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          query: ['@tanstack/react-query'],
          charts: ['recharts'],
        },
      },
    },
  },

  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    // Playwright specs also match `*.spec.ts`. They need a running stack and a
    // real browser, so vitest must not try to run them.
    exclude: ['node_modules', 'dist', 'e2e/**'],
  },
});
