import { describe, expect, it } from 'vitest'
import type { EventoCal, TareaCal } from '../schemas'
import {
  aHora,
  ajustar,
  alternarEquipo,
  aMinutos,
  arrastrar,
  caja,
  colocarEventos,
  cuposCelda,
  diaSemana,
  esIso,
  fechaLarga,
  leerEquipo,
  leerVista,
  moverADia,
  moverEnRejilla,
  navegar,
  rangoVista,
  rejillaMes,
  repartirPorDia,
  sumarDias,
  textoHora,
  tituloVista,
  tramo,
} from './logicaCalendario'

function ev(p: Partial<EventoCal>): EventoCal {
  return {
    id: 'e1',
    titulo: 'Evento',
    dia: '2026-10-08',
    dia_fin: '2026-10-08',
    hora: '10:00',
    hora_fin: '11:00',
    todo_el_dia: false,
    editable: true,
    invitados: [],
    ubicacion: '',
    descripcion: '',
    meet: false,
    meet_url: '',
    recurrente: false,
    serie_id: '',
    link: '',
    docs: [],
    recordar: '',
    erp_meeting: '',
    color: '#4285F4',
    mio: true,
    owner: null,
    ...p,
  }
}

const tarea = (id: number, fecha: string): TareaCal => ({ id, titulo: `T${id}`, estado: 'pendiente', fecha, client_id: null, cliente: '', asignados: [] })

describe('fechas', () => {
  it('valida ISO', () => {
    expect(esIso('2026-10-08')).toBe(true)
    expect(esIso('2026-02-30')).toBe(false)
    expect(esIso('8/10/2026')).toBe(false)
    expect(esIso(null)).toBe(false)
  })
  it('suma días cruzando el cambio de hora y de año', () => {
    expect(sumarDias('2026-10-24', 2)).toBe('2026-10-26')
    expect(sumarDias('2026-12-31', 1)).toBe('2027-01-01')
    expect(sumarDias('2026-03-01', -1)).toBe('2026-02-28')
  })
  it('lunes = 0, domingo = 6', () => {
    expect(diaSemana('2026-10-05')).toBe(0)
    expect(diaSemana('2026-10-11')).toBe(6)
  })
  it('fecha larga', () => {
    expect(fechaLarga('2026-10-08')).toBe('jueves, 8 de octubre de 2026')
  })
})

describe('rango de cada vista', () => {
  it('mes: semanas completas de lunes a domingo', () => {
    const r = rangoVista('mes', '2026-10-08')
    expect(r.desde).toBe('2026-09-28')
    expect(r.hasta).toBe('2026-11-01')
    expect(r.dias).toHaveLength(35)
  })
  it('mes que empieza en lunes y febrero de 4 semanas', () => {
    expect(rangoVista('mes', '2026-06-15').desde).toBe('2026-06-01')
    const f = rangoVista('mes', '2027-02-10')
    expect(f.desde).toBe('2027-02-01')
    expect(f.hasta).toBe('2027-02-28')
  })
  it('semana, día y agenda', () => {
    expect(rangoVista('semana', '2026-10-08')).toMatchObject({ desde: '2026-10-05', hasta: '2026-10-11' })
    expect(rangoVista('dia', '2026-10-08').dias).toEqual(['2026-10-08'])
    const a = rangoVista('agenda', '2026-10-08')
    expect(a.hasta).toBe('2026-11-06')
    expect(a.dias).toHaveLength(30)
  })
  it('nunca pasa de 100 días (límite de la API)', () => {
    for (const d of ['2026-01-01', '2026-02-15', '2026-08-31', '2027-03-01']) {
      expect(rangoVista('mes', d).dias.length).toBeLessThanOrEqual(42)
    }
  })
})

describe('navegación y títulos', () => {
  it('anterior / siguiente', () => {
    expect(navegar('mes', '2026-01-31', 1)).toBe('2026-02-01')
    expect(navegar('mes', '2026-01-15', -1)).toBe('2025-12-01')
    expect(navegar('semana', '2026-10-08', 1)).toBe('2026-10-12')
    expect(navegar('dia', '2026-10-08', -1)).toBe('2026-10-07')
    expect(navegar('agenda', '2026-10-08', 1)).toBe('2026-11-07')
  })
  it('títulos como el antiguo', () => {
    expect(tituloVista('mes', '2026-10-08')).toBe('Octubre 2026')
    expect(tituloVista('semana', '2026-10-08')).toBe('5 Octubre – 11 Octubre 2026')
    expect(tituloVista('semana', '2026-12-30')).toBe('28 Diciembre – 3 Enero 2027')
    expect(tituloVista('dia', '2026-10-07')).toBe('Mié 7 de Octubre 2026')
    expect(tituloVista('agenda', '2026-10-08')).toBe('Agenda · 8 Octubre – 6 Noviembre')
  })
})

describe('rejilla del mes', () => {
  it('marca los días de fuera del mes', () => {
    const s = rejillaMes('2026-10-08')
    expect(s).toHaveLength(5)
    expect(s[0][0]).toEqual({ iso: '2026-09-28', delMes: false })
    expect(s[0][3]).toEqual({ iso: '2026-10-01', delMes: true })
    expect(s[4][6]).toEqual({ iso: '2026-11-01', delMes: false })
  })
})

describe('URL', () => {
  it('vista y equipo', () => {
    expect(leerVista('semana', 'mes')).toBe('semana')
    expect(leerVista('xx', 'agenda')).toBe('agenda')
    expect(leerVista(null, 'mes')).toBe('mes')
    expect(leerEquipo('2,3,x,3,0')).toEqual([2, 3])
    expect(leerEquipo(null)).toEqual([])
    expect(alternarEquipo([2, 3], 3)).toEqual([2])
    expect(alternarEquipo([2], 5)).toEqual([2, 5])
  })
})

describe('horas y paso de 15 minutos', () => {
  it('convierte', () => {
    expect(aMinutos('07:30')).toBe(450)
    expect(Number.isNaN(aMinutos(''))).toBe(true)
    expect(aHora(450)).toBe('07:30')
    expect(aHora(1440)).toBe('00:00')
  })
  it('ajusta al paso y acota', () => {
    expect(ajustar(452)).toBe(450)
    expect(ajustar(458)).toBe(465)
    expect(ajustar(-20)).toBe(0)
    expect(ajustar(1500)).toBe(1440)
  })
  it('mover conserva la duración y no se sale del día', () => {
    expect(arrastrar('mover', { ini: 600, fin: 660 }, 700, 10)).toEqual({ ini: 690, fin: 750 })
    expect(arrastrar('mover', { ini: 600, fin: 660 }, 1430, 0)).toEqual({ ini: 1380, fin: 1440 })
    expect(arrastrar('mover', { ini: 600, fin: 660 }, 5, 30)).toEqual({ ini: 0, fin: 60 })
  })
  it('redimensionar deja al menos 15 minutos', () => {
    expect(arrastrar('abajo', { ini: 600, fin: 660 }, 722)).toEqual({ ini: 600, fin: 720 })
    expect(arrastrar('abajo', { ini: 600, fin: 660 }, 590)).toEqual({ ini: 600, fin: 615 })
    expect(arrastrar('arriba', { ini: 600, fin: 660 }, 556)).toEqual({ ini: 555, fin: 660 })
    expect(arrastrar('arriba', { ini: 600, fin: 660 }, 700)).toEqual({ ini: 645, fin: 660 })
  })
  it('cuerpo del PATCH al mover', () => {
    expect(moverEnRejilla('2026-10-09', 600, 675)).toEqual({ fecha: '2026-10-09', hora: '10:00', hora_fin: '11:15', fecha_fin: '' })
    expect(moverEnRejilla('2026-10-09', 1380, 1440).hora_fin).toBe('23:59')
    expect(moverADia(ev({}), '2026-10-12')).toEqual({ fecha: '2026-10-12', hora: '10:00', hora_fin: '11:00', fecha_fin: '' })
    expect(moverADia(ev({ todo_el_dia: true, hora: '', hora_fin: '', dia_fin: '2026-10-10' }), '2026-10-12')).toEqual({ fecha: '2026-10-12', hora: '', hora_fin: '', fecha_fin: '2026-10-14' })
  })
})

describe('eventos por día y solapes', () => {
  it('reparte eventos de varios días y tareas', () => {
    const dias = ['2026-10-08', '2026-10-09', '2026-10-10']
    const m = repartirPorDia(
      [
        ev({ id: 'a', todo_el_dia: true, hora: '', hora_fin: '', dia_fin: '2026-10-09' }),
        ev({ id: 'b', hora: '12:00', hora_fin: '13:00' }),
        ev({ id: 'c', hora: '09:00', hora_fin: '09:30' }),
        ev({ id: 'd', hora: '23:00', hora_fin: '01:00', dia_fin: '2026-10-09' }),
      ],
      [tarea(1, '2026-10-10'), tarea(2, '2026-10-08 00:00:00')],
      dias,
    )
    expect(m.get('2026-10-08')!.todoElDia.map((e) => e.id)).toEqual(['a'])
    expect(m.get('2026-10-08')!.conHora.map((e) => e.id)).toEqual(['c', 'b', 'd'])
    expect(m.get('2026-10-09')!.todoElDia.map((e) => e.id)).toEqual(['a', 'd'])
    expect(m.get('2026-10-10')!.tareas.map((t) => t.id)).toEqual([1])
    expect(m.get('2026-10-08')!.tareas.map((t) => t.id)).toEqual([2])
  })
  it('tramo: sin fin, fin anterior al inicio y fin otro día', () => {
    expect(tramo(ev({ hora: '10:00', hora_fin: '' }))).toEqual({ ini: 600, fin: 660 })
    expect(tramo(ev({ hora: '10:00', hora_fin: '09:00' }))).toEqual({ ini: 600, fin: 630 })
    expect(tramo(ev({ hora: '23:00', hora_fin: '01:00', dia_fin: '2026-10-09' }))).toEqual({ ini: 1380, fin: 1440 })
  })
  it('columnas para los solapes', () => {
    const c = colocarEventos([
      ev({ id: 'a', hora: '09:00', hora_fin: '10:00' }),
      ev({ id: 'b', hora: '09:30', hora_fin: '10:30' }),
      ev({ id: 'c', hora: '10:00', hora_fin: '11:00' }),
      ev({ id: 'd', hora: '12:00', hora_fin: '13:00' }),
    ])
    const por = Object.fromEntries(c.map((x) => [x.ev.id, [x.col, x.cols]]))
    expect(por).toEqual({ a: [0, 2], b: [1, 2], c: [0, 2], d: [0, 1] })
  })
  it('caja en px y %', () => {
    expect(caja({ ini: 600, fin: 660, col: 1, cols: 2 })).toEqual({ top: 460, height: 46, left: 50, width: 50 })
    expect(caja({ ini: 600, fin: 610, col: 0, cols: 1 }).height).toBe(22)
  })
})

describe('textos', () => {
  it('hora y cupos de la celda', () => {
    expect(textoHora(ev({}))).toBe('10:00 – 11:00')
    expect(textoHora(ev({ todo_el_dia: true }))).toBe('Todo el día')
    expect(cuposCelda(6, 0)).toEqual({ tareas: 4, eventos: 0, mas: 2 })
    expect(cuposCelda(6, 3)).toEqual({ tareas: 3, eventos: 2, mas: 4 })
    expect(cuposCelda(1, 1)).toEqual({ tareas: 1, eventos: 1, mas: 0 })
  })
})
