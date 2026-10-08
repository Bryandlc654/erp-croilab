/* Lógica pura del calendario (probada en logicaCalendario.test.ts): fechas en
   'AAAA-MM-DD' sin zonas horarias (todo es hora de Madrid, como la API), rango
   y título de cada vista, rejilla del mes, reparto de eventos por día,
   columnas para los solapes y el paso de 15 minutos al arrastrar. */

import type { EventoCal, TareaCal } from '../schemas'

export type Vista = 'mes' | 'semana' | 'dia' | 'agenda'
export const VISTAS: Vista[] = ['mes', 'semana', 'dia', 'agenda']

/* Alto de una hora en las vistas Semana/Día (px) y paso al arrastrar (min). */
export const ALTO_HORA = 46
export const PASO = 15
export const DIAS_AGENDA = 30

export const MESES_CAL = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
export const DIAS_SEMANA = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']
const DIAS_LARGOS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

const dos = (n: number) => String(n).padStart(2, '0')

/* ---------- Fechas ISO ---------- */

export function esIso(s: string | null | undefined): s is string {
  if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return false
  const [a, m, d] = s.split('-').map(Number)
  const f = new Date(a, m - 1, d)
  return f.getFullYear() === a && f.getMonth() === m - 1 && f.getDate() === d
}

export function aIso(d: Date) {
  return `${d.getFullYear()}-${dos(d.getMonth() + 1)}-${dos(d.getDate())}`
}

/* A mediodía para que un cambio de hora (marzo/octubre) no mueva el día. */
export function deIso(iso: string) {
  const [a, m, d] = iso.split('-').map(Number)
  return new Date(a, m - 1, d, 12)
}

export function sumarDias(iso: string, n: number) {
  const d = deIso(iso)
  d.setDate(d.getDate() + n)
  return aIso(d)
}

export function diasEntre(desde: string, hasta: string) {
  return Math.round((deIso(hasta).getTime() - deIso(desde).getTime()) / 86_400_000)
}

/* 0 = lunes … 6 = domingo. */
export function diaSemana(iso: string) {
  return (deIso(iso).getDay() + 6) % 7
}

export function lunesDe(iso: string) {
  return sumarDias(iso, -diaSemana(iso))
}

export function primeroDeMes(iso: string) {
  return iso.slice(0, 8) + '01'
}

export function ultimoDeMes(iso: string) {
  const d = deIso(primeroDeMes(iso))
  return aIso(new Date(d.getFullYear(), d.getMonth() + 1, 0, 12))
}

export function sumarMeses(iso: string, n: number) {
  const d = deIso(primeroDeMes(iso))
  return aIso(new Date(d.getFullYear(), d.getMonth() + n, 1, 12))
}

export function listaDias(desde: string, hasta: string) {
  const out: string[] = []
  for (let d = desde; d <= hasta; d = sumarDias(d, 1)) out.push(d)
  return out
}

/* ---------- Vistas: rango, navegación y título ---------- */

export type Rango = { desde: string; hasta: string; dias: string[] }

/* Lo que se pinta (y se pide a la API): el mes con sus semanas completas,
   la semana de lunes a domingo, el día o 30 días de agenda. */
export function rangoVista(vista: Vista, d: string): Rango {
  let desde = d
  let hasta = d
  if (vista === 'mes') {
    desde = lunesDe(primeroDeMes(d))
    hasta = sumarDias(lunesDe(ultimoDeMes(d)), 6)
  } else if (vista === 'semana') {
    desde = lunesDe(d)
    hasta = sumarDias(desde, 6)
  } else if (vista === 'agenda') {
    hasta = sumarDias(d, DIAS_AGENDA - 1)
  }
  return { desde, hasta, dias: listaDias(desde, hasta) }
}

/* Fecha de la página anterior (-1) o siguiente (+1). */
export function navegar(vista: Vista, d: string, dir: -1 | 1) {
  if (vista === 'mes') return sumarMeses(d, dir)
  if (vista === 'semana') return sumarDias(lunesDe(d), 7 * dir)
  if (vista === 'agenda') return sumarDias(d, DIAS_AGENDA * dir)
  return sumarDias(d, dir)
}

function diaMes(iso: string) {
  const f = deIso(iso)
  return `${f.getDate()} ${MESES_CAL[f.getMonth()]}`
}

/* «Octubre 2026», «5 Octubre – 11 Octubre 2026», «Mié 8 de Octubre 2026», «Agenda · 8 Octubre – 6 Noviembre». */
export function tituloVista(vista: Vista, d: string) {
  const f = deIso(d)
  if (vista === 'mes') return `${MESES_CAL[f.getMonth()]} ${f.getFullYear()}`
  if (vista === 'semana') {
    const { desde, hasta } = rangoVista('semana', d)
    return `${diaMes(desde)} – ${diaMes(hasta)} ${deIso(hasta).getFullYear()}`
  }
  if (vista === 'agenda') {
    const { hasta } = rangoVista('agenda', d)
    return `Agenda · ${diaMes(d)} – ${diaMes(hasta)}`
  }
  return `${DIAS_SEMANA[diaSemana(d)]} ${f.getDate()} de ${MESES_CAL[f.getMonth()]} ${f.getFullYear()}`
}

/* «miércoles, 8 de octubre de 2026» (popover de evento). */
export function fechaLarga(iso: string) {
  const f = deIso(iso)
  return `${DIAS_LARGOS[diaSemana(iso)]}, ${f.getDate()} de ${MESES_CAL[f.getMonth()].toLowerCase()} de ${f.getFullYear()}`
}

/* Rejilla del mes: semanas de 7 días (lunes primero) con si el día es del mes. */
export type CeldaMes = { iso: string; delMes: boolean }

export function rejillaMes(d: string): CeldaMes[][] {
  const mes = d.slice(0, 7)
  const { dias } = rangoVista('mes', d)
  const semanas: CeldaMes[][] = []
  for (let i = 0; i < dias.length; i += 7) semanas.push(dias.slice(i, i + 7).map((iso) => ({ iso, delMes: iso.slice(0, 7) === mes })))
  return semanas
}

/* ---------- URL ---------- */

export function leerVista(v: string | null, porDefecto: Vista): Vista {
  return VISTAS.includes(v as Vista) ? (v as Vista) : porDefecto
}

export function leerEquipo(v: string | null): number[] {
  if (!v) return []
  return [...new Set(v.split(',').filter((x) => /^\d+$/.test(x)).map(Number))].filter((n) => n > 0)
}

export function alternarEquipo(sel: number[], id: number) {
  return sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]
}

/* ---------- Horas ---------- */

/* 'HH:MM' → minutos desde las 00:00 (NaN si no es una hora). */
export function aMinutos(h: string) {
  const m = /^(\d{1,2}):(\d{2})/.exec(h)
  return m ? +m[1] * 60 + +m[2] : NaN
}

/* Minutos → 'HH:MM' (24:00 sale como 00:00, como el antiguo). */
export function aHora(min: number) {
  const m = ((Math.round(min) % 1440) + 1440) % 1440
  return `${dos(Math.floor(m / 60))}:${dos(m % 60)}`
}

/* Redondea al paso más cercano (15 min) y acota a [min, max]. */
export function ajustar(min: number, paso = PASO, lo = 0, hi = 1440) {
  return Math.max(lo, Math.min(hi, Math.round(min / paso) * paso))
}

/* Posición vertical en px → minutos del día. */
export function pxAMinutos(y: number, alto = ALTO_HORA) {
  return (y / alto) * 60
}

export function sumarHora(h: string, min: number) {
  const t = aMinutos(h)
  if (Number.isNaN(t)) return h
  return aHora(Math.min(23 * 60 + 59, t + min))
}

/* ---------- Eventos por día ---------- */

/* Un evento de varios días (o que cruza la medianoche) sale en cada día. */
export function cubreDia(e: Pick<EventoCal, 'dia' | 'dia_fin'>, iso: string) {
  const fin = e.dia_fin && e.dia_fin >= e.dia ? e.dia_fin : e.dia
  return iso >= e.dia && iso <= fin
}

export type PorDia = { todoElDia: EventoCal[]; conHora: EventoCal[]; tareas: TareaCal[] }

/* Ordena: los de todo el día por título; los de hora por hora de inicio. */
export function repartirPorDia(eventos: EventoCal[], tareas: TareaCal[], dias: string[]): Map<string, PorDia> {
  const m = new Map<string, PorDia>(dias.map((d) => [d, { todoElDia: [], conHora: [], tareas: [] }]))
  for (const e of eventos) {
    for (const d of dias) {
      if (!cubreDia(e, d)) continue
      const g = m.get(d)!
      // Un evento con hora que sigue al día siguiente: en ese día va arriba, como de día completo.
      if (e.todo_el_dia || d !== e.dia) g.todoElDia.push(e)
      else g.conHora.push(e)
    }
  }
  for (const t of tareas) m.get(t.fecha.slice(0, 10))?.tareas.push(t)
  for (const g of m.values()) {
    g.conHora.sort((a, b) => a.hora.localeCompare(b.hora) || a.hora_fin.localeCompare(b.hora_fin))
  }
  return m
}

/* ---------- Rejilla de horas: posiciones y solapes ---------- */

export type Colocado = { ev: EventoCal; ini: number; fin: number; col: number; cols: number }

/* Minutos de inicio y fin dentro del día (fin ≥ inicio + 30 si viene mal; si
   acaba otro día, hasta las 24:00). */
export function tramo(e: Pick<EventoCal, 'hora' | 'hora_fin' | 'dia' | 'dia_fin'>) {
  const ini = aMinutos(e.hora) || 0
  let fin = e.hora_fin ? aMinutos(e.hora_fin) : ini + 60
  if (e.dia_fin && e.dia_fin > e.dia) fin = 1440
  if (Number.isNaN(fin) || fin <= ini) fin = Math.min(1440, ini + 30)
  return { ini, fin }
}

/* Reparte en columnas los eventos que se solapan (como cal_layout del antiguo):
   cada grupo conectado de solapes usa tantas columnas como necesita. */
export function colocarEventos(evs: EventoCal[]): Colocado[] {
  const lista = evs.map((ev) => ({ ev, ...tramo(ev) })).sort((a, b) => a.ini - b.ini || a.fin - b.fin)
  const out: Colocado[] = []
  let grupo: Colocado[] = []
  let finGrupo = -1
  let finCols: number[] = []
  const cerrar = () => {
    for (const g of grupo) g.cols = finCols.length
    out.push(...grupo)
    grupo = []
    finCols = []
    finGrupo = -1
  }
  for (const e of lista) {
    if (grupo.length && e.ini >= finGrupo) cerrar()
    let col = finCols.findIndex((f) => e.ini >= f)
    if (col < 0) {
      col = finCols.length
      finCols.push(e.fin)
    } else finCols[col] = e.fin
    grupo.push({ ...e, col, cols: 1 })
    finGrupo = Math.max(finGrupo, e.fin)
  }
  cerrar()
  return out
}

/* top/alto en px (mínimo 22 px para que se lea) y left/ancho en %. */
export function caja(c: Pick<Colocado, 'ini' | 'fin' | 'col' | 'cols'>, alto = ALTO_HORA) {
  const ancho = 100 / Math.max(1, c.cols)
  return {
    top: (c.ini / 60) * alto,
    height: Math.max(22, ((c.fin - c.ini) / 60) * alto),
    left: c.col * ancho,
    width: ancho,
  }
}

/* ---------- Arrastrar ---------- */

export type Modo = 'mover' | 'arriba' | 'abajo'

/* Nuevo tramo al arrastrar: `y` es el minuto bajo el cursor y `agarre` la
   distancia desde el inicio del evento a donde se cogió. Paso de 15 min,
   dentro del día y con al menos 15 min de duración. */
export function arrastrar(modo: Modo, orig: { ini: number; fin: number }, y: number, agarre = 0) {
  const dur = orig.fin - orig.ini
  if (modo === 'mover') {
    const ini = ajustar(y - agarre, PASO, 0, 1440 - dur)
    return { ini, fin: ini + dur }
  }
  if (modo === 'abajo') return { ini: orig.ini, fin: ajustar(y, PASO, orig.ini + PASO, 1440) }
  return { ini: ajustar(y, PASO, 0, orig.fin - PASO), fin: orig.fin }
}

/* Cuerpo del PATCH `solo_fechas` al llevar un evento a otro día (vista Mes):
   conserva la hora y la duración en días. */
export function moverADia(e: Pick<EventoCal, 'dia' | 'dia_fin' | 'hora' | 'hora_fin' | 'todo_el_dia'>, nuevo: string) {
  const largo = e.dia_fin && e.dia_fin > e.dia ? diasEntre(e.dia, e.dia_fin) : 0
  return {
    fecha: nuevo,
    hora: e.todo_el_dia ? '' : e.hora,
    hora_fin: e.todo_el_dia ? '' : e.hora_fin,
    fecha_fin: largo ? sumarDias(nuevo, largo) : '',
  }
}

/* Mover/redimensionar en la rejilla: día y minutos. Si fin llega a 24:00 se
   guarda 23:59 (Google no acepta 24:00 y así no cambia de día). */
export function moverEnRejilla(dia: string, ini: number, fin: number) {
  return { fecha: dia, hora: aHora(ini), hora_fin: fin >= 1440 ? '23:59' : aHora(fin), fecha_fin: '' }
}

/* ---------- Textos ---------- */

export function textoHora(e: Pick<EventoCal, 'todo_el_dia' | 'hora' | 'hora_fin'>) {
  if (e.todo_el_dia) return 'Todo el día'
  return e.hora_fin ? `${e.hora} – ${e.hora_fin}` : e.hora
}

/* «+N más» de una celda del mes: hasta 4 tareas (3 si hay eventos) y 2 eventos. */
export function cuposCelda(nTareas: number, nEventos: number, maxTareas = 4, maxEventos = 2) {
  const t = Math.min(nTareas, nEventos ? maxTareas - 1 : maxTareas)
  const e = Math.min(nEventos, maxEventos)
  return { tareas: t, eventos: e, mas: nTareas - t + (nEventos - e) }
}
