import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '../shared/api/client'

/* Un 4xx no se arregla reintentando (sin sesión, sin permiso, no existe,
   contrato roto…): se enseña ya. Los fallos de red o 5xx se reintentan una vez. */
export function reintentar(fallos: number, error: unknown) {
  if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false
  if (error instanceof ApiError && error.codigo === 'contrato') return false
  return fallos < 1
}

export function crearQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Medio minuto «fresco»: navegar entre vistas no repite peticiones,
        // y los cambios propios ya invalidan lo que toca.
        staleTime: 30_000,
        retry: reintentar,
      },
      mutations: {
        // Una mutación repetida podría aplicarse dos veces: nunca se reintenta sola.
        retry: false,
      },
    },
  })
}
