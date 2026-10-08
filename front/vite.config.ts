import { defineConfig } from 'vitest/config'
import { loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const __dirname = dirname(fileURLToPath(import.meta.url))

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, __dirname, 'VITE_')
  // Los recursos se piden bajo el mismo prefijo que usa el router (VITE_BASE_PATH);
  // Vite necesita la barra final.
  const base = (env.VITE_BASE_PATH || '/admin').replace(/\/+$/, '') + '/'

  return {
    base,
    plugins: [react(), tailwindcss()],
    build: {
      outDir: resolve(__dirname, 'dist'),
      emptyOutDir: true,
      // Sin mapas de código en producción: publicarían el código fuente completo.
      sourcemap: false,
    },
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      css: false,
      restoreMocks: true,
      // Las pruebas llaman a la API por rutas relativas: el VITE_API_URL del
      // .env de despliegue las convertiría en absolutas y no casarían con los mocks.
      env: { VITE_API_URL: '' },
    },
  }
})
