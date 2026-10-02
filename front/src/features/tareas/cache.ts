import type { InfiniteData } from '@tanstack/react-query'
import type { Tarea, TareasPagina } from './schemas'

export type TareasCache = InfiniteData<TareasPagina, number>

/* Cambia una tarea en todas las páginas cargadas de una lista en caché.
   Devuelve el mismo objeto si no estaba, para no provocar renders de más. */
export function reemplazarTarea(d: TareasCache | undefined, id: number, nueva: Tarea): TareasCache | undefined {
  if (!d || !d.pages.some((p) => p.items.some((t) => t.id === id))) return d
  return {
    ...d,
    pages: d.pages.map((p) => ({ ...p, items: p.items.map((t) => (t.id === id ? nueva : t)) })),
  }
}

/* Quita una tarea de las páginas cargadas (borrado optimista). */
export function quitarTarea(d: TareasCache | undefined, id: number): TareasCache | undefined {
  if (!d || !d.pages.some((p) => p.items.some((t) => t.id === id))) return d
  return {
    ...d,
    pages: d.pages.map((p) => ({ ...p, items: p.items.filter((t) => t.id !== id) })),
  }
}
