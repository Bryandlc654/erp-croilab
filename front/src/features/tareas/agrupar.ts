import { ORDEN_ESTADOS } from './constantes'
import type { Estado, Tarea } from './schemas'

export type GrupoEstado = { tipo: 'estado'; clave: string; estado: Estado; tareas: Tarea[] }
export type GrupoCliente = { tipo: 'cliente'; clave: string; nombre: string; iniciales: string; tareas: Tarea[] }
export type GrupoTareas = GrupoEstado | GrupoCliente

/* Grupos del tablero: por estado en la vista de un cliente y por cliente en las
   generales. Se respeta el orden en que llegan (la API ya ordena por cliente y
   estado) y la clave es estable para recordar qué grupos están plegados. */
export function agruparTareas(tareas: Tarea[], view: string): GrupoTareas[] {
  if (view === 'cliente') {
    return ORDEN_ESTADOS.map((e): GrupoEstado => ({ tipo: 'estado', clave: `est-${e}`, estado: e, tareas: tareas.filter((t) => t.estado === e) })).filter(
      (g) => g.tareas.length > 0,
    )
  }
  const m = new Map<number, GrupoCliente>()
  for (const t of tareas) {
    let g = m.get(t.client_id)
    if (!g) {
      g = { tipo: 'cliente', clave: `cli-${t.client_id}`, nombre: t.client_name || 'Sin cliente', iniciales: t.client_iniciales || '', tareas: [] }
      m.set(t.client_id, g)
    }
    g.tareas.push(t)
  }
  return [...m.values()]
}
