import { resolve } from 'node:path';
import { defineConfig } from 'vite';

const browserDefines = {\n  'process.env.NODE_ENV': JSON.stringify('production'),\n};\n\nconst testConfig = {
  environment: 'node',
  include: [
    'assets-src/admin/__tests__/**/*.test.js',
    'assets-src/viewer/__tests__/**/*.test.js',
    'assets-src/pwa/__tests__/**/*.test.js',
  ],
};

export default defineConfig(({ mode }) => {
  if (mode === 'pwa') {
    return {
      build: {
        outDir: resolve(import.meta.dirname, 'assets/pwa'),
        emptyOutDir: true,
        sourcemap: true,
        lib: {
          entry: resolve(import.meta.dirname, 'assets-src/pwa/service-worker.js'),
          name: 'RouteMapsServiceWorker',
          formats: ['iife'],
          fileName: () => 'service-worker.js',
        },
      },
      test: testConfig,
    };
  }

  if (mode === 'viewer') {
    return {
      build: {
        outDir: resolve(import.meta.dirname, 'assets/viewer'),
        emptyOutDir: true,
        sourcemap: true,
        manifest: true,
        lib: {
          entry: resolve(import.meta.dirname, 'assets-src/viewer/index.js'),
          formats: ['es'],
        },
        rollupOptions: {
          output: {
            entryFileNames: 'routemaps-viewer-[hash].js',
            assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css')
              ? 'routemaps-viewer-[hash][extname]'
              : '[name]-[hash][extname]',
          },
        },
      },
      test: testConfig,
    };
  }

  return {
    build: {
      outDir: resolve(import.meta.dirname, 'assets/admin'),
      emptyOutDir: true,
      sourcemap: true,
      lib: {
        entry: resolve(import.meta.dirname, 'assets-src/admin/index.js'),
        name: 'RouteMapsAdminApp',
        formats: ['iife'],
        fileName: () => 'routemaps-admin.js',
        cssFileName: 'routemaps-admin',
      },
      rollupOptions: {
        output: {
          assetFileNames: (assetInfo) => assetInfo.name?.endsWith('.css') ? 'routemaps-admin.css' : '[name]-[hash][extname]',
        },
      },
    },
    test: testConfig,
  };
});
