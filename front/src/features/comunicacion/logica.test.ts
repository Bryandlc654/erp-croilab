import { describe, expect, it } from 'vitest'
import {
  actualizadoRelativo,
  agruparAvisos,
  agruparMensajes,
  enlaceWhatsapp,
  etiquetaDia,
  fechaReunion,
  fusionarMensajes,
  horaCorta,
  listaCorreos,
  reemplazarUltimoToken,
  sugerirCorreos,
  sumarMinutos,
  textoEscribiendo,
  textoReserva,
  trozosTexto,
  ultimoToken,
  vistaPrevia,
} from './logica'
import type { AvisoChatT, Mensaje, Sala } from './schemas'

const ahora = new Date(2026, 9, 8, 12, 0, 0) // 8 oct 2026 (jueves), 12:00

function msg(id: number, autor_id: number, creado: string, extra: Partial<Mensaje> = {}): Mensaje {
  return { id, sala_id: 1, autor_id, autor: `u${autor_id}`, foto: null, texto: `m${id}`, creado, editado: false, borrado: false, responde_a: null, adjuntos: [], reacciones: [], ...extra }
}

describe('fechas del chat', () => {
  it('hora corta de la lista: hoy, ayer, día de la semana y dd/mm', () => {
    expect(horaCorta('2026-10-08 09:05:00', ahora)).toBe('09:05')
    expect(horaCorta('2026-10-07 23:59:00', ahora)).toBe('ayer')
    expect(horaCorta('2026-10-05 10:00:00', ahora)).toBe('lun')
    expect(horaCorta('2026-10-01 10:00:00', ahora)).toBe('01/10')
  })
  it('separadores de día', () => {
    expect(etiquetaDia('2026-10-08', ahora)).toBe('Hoy')
    expect(etiquetaDia('2026-10-07', ahora)).toBe('Ayer')
    expect(etiquetaDia('2026-09-30', ahora)).toBe('30/09/2026')
  })
  it('actualizado relativo de los tickets', () => {
    expect(actualizadoRelativo('2026-10-08 11:59:30', ahora)).toBe('ahora')
    expect(actualizadoRelativo('2026-10-08 11:20:00', ahora)).toBe('40 min')
    expect(actualizadoRelativo('2026-10-08 02:00:00', ahora)).toBe('10 h')
    expect(actualizadoRelativo('2026-10-01 02:00:00', ahora)).toBe('01/10/2026')
  })
  it('fecha de reunión y suma de minutos', () => {
    expect(fechaReunion('2026-10-08', '10:00')).toBe('8 oct 2026 · 10:00')
    expect(sumarMinutos('10:30', 90)).toBe('12:00')
    expect(sumarMinutos('23:30', 90)).toBe('23:59')
  })
})

describe('salas y mensajes', () => {
  const base: Sala = { id: 1, tipo: 'grupo', nombre: 'SEO', miembros: [{ id: 1, username: 'yo', foto: null }, { id: 2, username: 'ana', foto: null }], otro_id: null, creado_por: 1, ultimo: null, no_leidos: 0 }
  const ult = (o: Partial<NonNullable<Sala['ultimo']>>) => ({ id: 9, texto: 'hola **ya**', autor_id: 2, autor: 'ana', creado: '2026-10-08 10:00:00', borrado: false, adjunto: false, ...o })

  it('vista previa como el antiguo', () => {
    expect(vistaPrevia(base, 1)).toBe('2 miembros')
    expect(vistaPrevia({ ...base, ultimo: ult({}) }, 1)).toBe('ana: hola ya')
    expect(vistaPrevia({ ...base, ultimo: ult({ autor_id: 1 }) }, 1)).toBe('Tú: hola **ya**'.replace(/\*\*/g, ''))
    expect(vistaPrevia({ ...base, tipo: 'dm', ultimo: ult({}) }, 1)).toBe('hola ya')
    expect(vistaPrevia({ ...base, ultimo: ult({ borrado: true }) }, 1)).toBe('Mensaje eliminado')
    expect(vistaPrevia({ ...base, ultimo: ult({ texto: '', adjunto: true }) }, 1)).toBe('ana: 📎 Adjunto')
  })

  it('agrupa mensajes seguidos del mismo autor y separa días', () => {
    const f = agruparMensajes([
      msg(1, 2, '2026-10-07 10:00:00'),
      msg(2, 2, '2026-10-08 10:00:00'),
      msg(3, 2, '2026-10-08 10:02:00'),
      msg(4, 1, '2026-10-08 10:03:00'),
      msg(5, 1, '2026-10-08 10:20:00'),
    ])
    expect(f.map((x) => [x.nuevoDia, x.continua])).toEqual([
      [true, false],
      [true, false],
      [false, true],
      [false, false],
      [false, false],
    ])
  })

  it('fusiona nuevos y cambiados por id, en orden', () => {
    const r = fusionarMensajes([msg(1, 1, 'x'), msg(3, 1, 'x')], [msg(2, 1, 'x'), { ...msg(3, 1, 'x'), texto: 'editado' }])
    expect(r.map((m) => [m.id, m.texto])).toEqual([
      [1, 'm1'],
      [2, 'm2'],
      [3, 'editado'],
    ])
  })

  it('trozos del texto: enlaces y menciones del equipo, sin HTML', () => {
    const t = trozosTexto('Hola @Ana, mira https://x.com/a. y @nadie <b>', ['ana', 'yo'], 'yo')
    expect(t).toEqual([
      { t: 'texto', v: 'Hola ' },
      { t: 'mencion', v: 'Ana', yo: false },
      { t: 'texto', v: ', mira ' },
      { t: 'enlace', v: 'https://x.com/a' },
      { t: 'texto', v: '. y @nadie <b>' },
    ])
    expect(trozosTexto('correo a@ana.com', ['ana'], 'yo')).toEqual([{ t: 'texto', v: 'correo a@ana.com' }])
    expect(trozosTexto('@yo', ['yo'], 'yo')).toEqual([{ t: 'mencion', v: 'yo', yo: true }])
  })

  it('texto de «escribiendo»', () => {
    expect(textoEscribiendo([])).toBe('')
    expect(textoEscribiendo(['ana'])).toBe('ana está escribiendo…')
    expect(textoEscribiendo(['ana', 'luis'])).toBe('ana y luis están escribiendo…')
    expect(textoEscribiendo(['a', 'b', 'c'])).toBe('3 escribiendo…')
  })

  it('pop-ups del avisador: uno por sala con «+N más»', () => {
    const a = (id: number, sala_id: number, grupo: boolean): AvisoChatT => ({ id, sala_id, autor_id: 2, autor: 'ana', foto: null, texto: `t${id}`, grupo, sala: grupo ? 'SEO' : 'ana' })
    expect(agruparAvisos([a(1, 1, true), a(2, 2, false), a(3, 1, true)])).toEqual([
      { sala_id: 1, autor: 'ana', foto: null, titulo: 'SEO · ana', texto: 't3 · +1 más' },
      { sala_id: 2, autor: 'ana', foto: null, titulo: 'ana', texto: 't2' },
    ])
  })
})

describe('invitados y enlaces', () => {
  it('último correo que se escribe y su sustitución', () => {
    expect(ultimoToken('a@x.com, Ma')).toBe('ma')
    expect(reemplazarUltimoToken('a@x.com, ma', 'marta@y.com')).toBe('a@x.com, marta@y.com, ')
    expect(reemplazarUltimoToken('ma', 'marta@y.com')).toBe('marta@y.com, ')
    expect(listaCorreos('A@x.com; b@y.com  no, a@x.com')).toEqual(['a@x.com', 'b@y.com'])
  })
  it('sugerencias sin repetir las ya puestas', () => {
    const todos = [
      { email: 'marta@y.com', nombre: 'Marta', tipo: 'contacto' as const },
      { email: 'mario@z.com', nombre: 'Mario', tipo: 'equipo' as const },
    ]
    expect(sugerirCorreos(todos, 'mar', 'marta@y.com, mar').map((c) => c.email)).toEqual(['mario@z.com'])
    expect(sugerirCorreos(todos, '', '')).toEqual([])
  })
  it('WhatsApp con prefijo 34 y texto de reserva', () => {
    expect(enlaceWhatsapp('600 111 222', 'hola')).toBe('https://wa.me/34600111222?text=hola')
    expect(enlaceWhatsapp('+44 7700 900123', 'x')).toBe('https://wa.me/447700900123?text=x')
    expect(enlaceWhatsapp('', 'x')).toBe('')
    expect(textoReserva('Ana', 'https://cal')).toBe('Hola Ana, te paso mi enlace para agendar nuestra reunión cuando mejor te venga: https://cal')
    expect(textoReserva('', 'https://cal')).toMatch(/^Hola, te paso/)
  })
})
