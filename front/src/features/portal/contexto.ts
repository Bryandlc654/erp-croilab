import { createContext, useContext } from 'react'
import type { Origen } from './api'
import type { Vista } from './logica'
import type { DatosPortal } from './schemas'

/* Bloques que el editor en vivo puede abrir. */
export type BloqueEditable = 'identidad' | 'estado' | 'plan' | 'accesos' | 'tareas' | 'informes'

export type PortalCtx = {
  datos: DatosPortal
  origen: Origen
  /* Raíz de las rutas del portal: /portal (cliente) o /clientes/:id/portal (equipo). */
  base: string
  ruta: (v: Vista, extra?: string) => string
  /* Solo en el editor en vivo: abre el modal de un bloque. */
  editar: ((b: BloqueEditable) => void) | null
}

export const PortalContext = createContext<PortalCtx | null>(null)

export function usePortal() {
  const c = useContext(PortalContext)
  if (!c) throw new Error('usePortal fuera del portal')
  return c
}

export const RUTA_VISTA: Record<Vista, string> = {
  inicio: '',
  metricas: 'metricas',
  tareas: 'tareas',
  progreso: 'progreso',
  reuniones: 'reuniones',
  soporte: 'soporte',
  informes: 'informes',
  facturas: 'facturas',
  metodo: 'metodo',
  accesos: 'accesos',
  plan: 'plan',
}

export function hacerRuta(base: string) {
  return (v: Vista, extra?: string) => {
    const p = RUTA_VISTA[v]
    return [base, p, extra].filter((s) => s !== undefined && s !== '').join('/')
  }
}
