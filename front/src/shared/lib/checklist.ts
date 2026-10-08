/* Lógica pura de la lista de control (checklist de task.php). */

export type IdChecklist = string | number

export type ItemChecklist = {
  id: IdChecklist
  texto: string
  hecho: boolean
  /* Personas asignadas (ids). */
  asignados?: number[]
}

/* Al marcar, el elemento se queda en su sitio 280 ms (tachado + destello) y
   luego baja al final: pendientes arriba, hechos abajo, cada grupo en su orden.
   `retenidos` guarda, para los recién marcados, el estado ANTERIOR con el que
   se siguen colocando mientras dura la animación. */
export const RETRASO_REORDEN_MS = 280

export function ordenarChecklist<T extends { id: IdChecklist; hecho: boolean }>(items: readonly T[], retenidos?: ReadonlyMap<IdChecklist, boolean>): T[] {
  const hecho = (it: T) => retenidos?.get(it.id) ?? it.hecho
  return [...items.filter((it) => !hecho(it)), ...items.filter((it) => hecho(it))]
}

export function progreso(items: readonly { hecho: boolean }[]) {
  return { hechos: items.filter((i) => i.hecho).length, total: items.length }
}

export function marcar<T extends { id: IdChecklist; hecho: boolean }>(items: readonly T[], id: IdChecklist, hecho: boolean): T[] {
  return items.map((it) => (it.id === id ? { ...it, hecho } : it))
}

/* Tras arrastrar en la lista visible (pendientes + hechos), el orden completo
   de ids que hay que guardar. Un pendiente soltado entre los hechos (o al
   revés) vuelve a su grupo: el orden visible siempre separa los dos. */
export function ordenTrasArrastre<T extends { id: IdChecklist; hecho: boolean }>(items: readonly T[], idsVisibles: readonly IdChecklist[]): IdChecklist[] {
  const porId = new Map(items.map((i) => [i.id, i]))
  const enOrden = idsVisibles.map((id) => porId.get(id)).filter((x): x is T => !!x)
  return ordenarChecklist(enOrden).map((i) => i.id)
}
