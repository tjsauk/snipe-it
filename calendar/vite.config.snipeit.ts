import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Build configuration for Snipe-IT integration.
// Outputs a single self-contained IIFE bundle directly into public/vendor/asset-calendar/.
// Usage: npm run build:snipeit
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: '../public/vendor/asset-calendar',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/adapters/mountStandalone.tsx',
      output: {
        entryFileNames: 'asset-calendar.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) return 'asset-calendar.css';
          return assetInfo.name ?? 'asset';
        },
        format: 'iife',
        name: 'AssetCalendarStandalone',
      },
    },
  },
});
