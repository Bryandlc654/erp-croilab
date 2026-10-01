import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))

  // Build into backend/ERP-Croilab/admin/tareas-react/dist
  export default defineConfig({
    plugins: [react()],
    build: {
      outDir: resolve(__dirname, '../backend/ERP-Croilab/admin/tareas-react/dist'),
      emptyOutDir: true,
      sourcemap: true,
    },
    server: {
      proxy: {
        '/api': {
          target: 'http://localhost',
          changeOrigin: true,
        },
      },
    },
  })
