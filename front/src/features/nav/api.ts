import { useQuery } from '@tanstack/react-query'
import type { z } from 'zod'
import { api } from '../../shared/api/client'
import { EquipoRespuesta, NavSchema } from './schemas'

export const clavesNav = {
  nav: ['nav'] as const,
  equipo: ['equipo'] as const,
}

/* Contadores del menú. Las pantallas que cambian tareas invalidan esta clave
   para que se refresquen. */
export function useNav() {
  return useQuery({
    queryKey: clavesNav.nav,
    queryFn: ({ signal }) => api('/api/v1/nav', { schema: NavSchema, signal }),
  })
}

/* Los mismos contadores, pero refrescándose cada 5 s mientras la pestaña
   está visible (como el sondeo de avisos del ERP): la campana del raíl se
   entera de los avisos nuevos sin recargar. Comparte caché con useNav. */
export function useSondeoNav() {
  return useQuery({
    queryKey: clavesNav.nav,
    queryFn: ({ signal }) => api('/api/v1/nav', { schema: NavSchema, signal }),
    refetchInterval: 5000,
  })
}

// Fuera del hook: un select estable no se vuelve a ejecutar en cada render.
const soloItems = (d: z.infer<typeof EquipoRespuesta>) => d.items

/* Personas activas: menú «Tareas de…», filtro de responsable y asignación. */
export function useEquipo() {
  return useQuery({
    queryKey: clavesNav.equipo,
    queryFn: ({ signal }) => api('/api/v1/equipo', { schema: EquipoRespuesta, signal }),
    select: soloItems,
    // El equipo cambia muy poco: no merece la pena pedirlo en cada pantalla.
    staleTime: 5 * 60_000,
  })
}
