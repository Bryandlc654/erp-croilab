import { describe, expect, it } from 'vitest'
import { agruparTareas } from './agrupar'
import { tarea } from '../../test/fixtures'

describe('agruparTareas()', () => {
  // La API ya ordena por cliente y estado: el orden de llegada se respeta.
  const tareas = [
    tarea({ id: 1, client_id: 10, client_name: 'Aeternum', client_iniciales: 'AE', estado: 'en proceso' }),
    tarea({ id: 2, client_id: 10, client_name: 'Aeternum', client_iniciales: 'AE', estado: 'pendiente' }),
    tarea({ id: 3, client_id: 20, client_name: 'Bodegas Ribera', client_iniciales: null, estado: 'pendiente' }),
    tarea({ id: 4, client_id: 30, client_name: null, client_iniciales: null, estado: 'completada' }),
    tarea({ id: 5, client_id: 10, client_name: 'Aeternum', client_iniciales: 'AE', estado: 'completada' }),
  ]

  it('en las vistas generales agrupa por cliente, en orden de aparición', () => {
    const grupos = agruparTareas(tareas, 'all')
    expect(grupos.map((g) => g.clave)).toEqual(['cli-10', 'cli-20', 'cli-30'])
    expect(grupos[0]).toMatchObject({ tipo: 'cliente', nombre: 'Aeternum', iniciales: 'AE' })
    // Una tarea del mismo cliente que llega más tarde va a su grupo, no a uno nuevo.
    expect(grupos[0].tareas.map((t) => t.id)).toEqual([1, 2, 5])
  })

  it('sin nombre o iniciales usa los valores por defecto', () => {
    const grupos = agruparTareas(tareas, 'mine')
    expect(grupos[1]).toMatchObject({ nombre: 'Bodegas Ribera', iniciales: '' })
    expect(grupos[2]).toMatchObject({ nombre: 'Sin cliente', iniciales: '' })
  })

  it('en la vista de un cliente agrupa por estado en el orden del tablero y sin grupos vacíos', () => {
    const grupos = agruparTareas(tareas, 'cliente')
    expect(grupos.map((g) => g.clave)).toEqual(['est-pendiente', 'est-en proceso', 'est-completada'])
    expect(grupos.every((g) => g.tipo === 'estado')).toBe(true)
    expect(grupos[0].tareas.map((t) => t.id)).toEqual([2, 3])
    expect(grupos[2].tareas.map((t) => t.id)).toEqual([4, 5])
  })

  it('sin tareas no hay grupos', () => {
    expect(agruparTareas([], 'all')).toEqual([])
    expect(agruparTareas([], 'cliente')).toEqual([])
  })
})
