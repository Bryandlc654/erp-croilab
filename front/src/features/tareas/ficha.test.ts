import { describe, expect, it } from 'vitest'
import { comentarioAKit, comentarioDelHash, eventosDeTarea, horasTexto, horasValidas, textoEvento } from './ficha'
import { completar, mesActual, ordenTrasMover } from './informe'

describe('ficha de la tarea', () => {
  it('texto de cada línea del historial', () => {
    expect(textoEvento({ tipo: 'estado', detalle: 'En proceso' })).toBe('cambió el estado a En proceso')
    expect(textoEvento({ tipo: 'asignados', detalle: '' })).toBe('quitó a todos los asignados')
    expect(textoEvento({ tipo: 'due_date', detalle: '2026-10-09' })).toBe('puso la fecha límite el 09/10/26')
    expect(textoEvento({ tipo: 'prioridad', detalle: 'Sin prioridad' })).toBe('quitó la prioridad')
  })

  it('las tareas sin historial enseñan «Tarea creada»', () => {
    expect(eventosDeTarea([], '2026-10-01 10:00:00').map((e) => e.texto)).toEqual(['Tarea creada'])
    const conCreada = eventosDeTarea([{ id: 1, tipo: 'creada', detalle: '', actor: 'ana', created_at: '2026-10-01 10:00:00' }], '2026-10-01 10:00:00')
    expect(conCreada).toHaveLength(1)
    expect(conCreada[0].actor).toBe('ana')
  })

  it('horas', () => {
    expect(horasTexto(90)).toBe('1,5 h')
    expect(horasTexto(0)).toBe('0 h')
    expect(horasValidas('1,5')).toBe('1.5')
    expect(horasValidas(' ')).toBe('')
    expect(horasValidas('dos')).toBeNull()
  })

  it('anclas y comentarios', () => {
    expect(comentarioDelHash('#c12')).toBe(12)
    expect(comentarioDelHash('#chk')).toBeNull()
    const k = comentarioAKit(
      {
        id: 3,
        autor: null,
        cuerpo: 'hola',
        checklist: [],
        reply_to: 1,
        editado: true,
        created_at: '2026-10-01 10:00:00',
        adjuntos: [{ id: 9, nombre: 'a.png', filename: '1_x.png', url: 'archivo.php?d=tasks&f=1_x.png', mime: 'image/png', es_imagen: true, admin_id: 1, created_at: '' }],
        reacciones: [],
        mio: false,
      },
      (r) => `https://api/${r}`,
    )
    expect(k.replyTo).toBe(1)
    expect(k.adjuntos?.[0].url).toBe('https://api/archivo.php?d=tasks&f=1_x.png')
  })

  it('listas: mes por defecto, orden tras arrastrar y completar', () => {
    expect(mesActual(new Date(2026, 9, 8))).toBe('Octubre 2026')
    expect(ordenTrasMover([[1, 2], [3, 4, 5]], 1, [5, 3, 4])).toEqual([1, 2, 5, 3, 4])
    expect(completar({ estado: 'completada' })).toBeNull()
    expect(completar({ estado: 'pendiente' })).toEqual({ estado: 'completada' })
  })
})
