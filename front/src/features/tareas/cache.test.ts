import { describe, expect, it } from 'vitest'
import { quitarTarea, reemplazarTarea, type TareasCache } from './cache'
import { tarea } from '../../test/fixtures'

function cache(): TareasCache {
  return {
    pageParams: [0, 2],
    pages: [
      { items: [tarea({ id: 1 }), tarea({ id: 2 })], total: 3, limit: 2, offset: 0, list_id: null },
      { items: [tarea({ id: 3 })], total: 3, limit: 2, offset: 2, list_id: null },
    ],
  }
}

describe('caché de listas de tareas', () => {
  it('reemplaza la tarea en la página donde esté', () => {
    const d = reemplazarTarea(cache(), 3, tarea({ id: 3, prioridad: 4 }))
    expect(d?.pages[1].items[0].prioridad).toBe(4)
    expect(d?.pages[0].items.map((t) => t.prioridad)).toEqual([0, 0])
  })

  it('si la tarea no está devuelve el mismo objeto (sin renders de más)', () => {
    const d = cache()
    expect(reemplazarTarea(d, 99, tarea({ id: 99 }))).toBe(d)
    expect(quitarTarea(d, 99)).toBe(d)
    expect(reemplazarTarea(undefined, 1, tarea())).toBeUndefined()
  })

  it('quita la tarea borrada de las páginas cargadas', () => {
    const d = quitarTarea(cache(), 2)
    expect(d?.pages.flatMap((p) => p.items.map((t) => t.id))).toEqual([1, 3])
  })
})
