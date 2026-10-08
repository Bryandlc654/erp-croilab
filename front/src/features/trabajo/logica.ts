import { aFecha, eur, mesNombre } from '../../shared/lib/formato'
import type { Inicio } from './schemas'

/* Lógica pura de Trabajo (inicio, avisos, actas), sin React. */

/* «Buenos días» antes de las 12, «Buenas tardes» antes de las 20. */
export function saludo(hora: number) {
  return hora < 12 ? 'Buenos días' : hora < 20 ? 'Buenas tardes' : 'Buenas noches'
}

const dos = (n: number) => String(n).padStart(2, '0')

/* Grupo de fecha de la bandeja: «Hoy», «Ayer», «Últimos 7 días» y luego el
   mes («Julio», o «Junio 2025» si no es de este año). */
export function grupoFecha(valor: string, ahora: Date = new Date()): string {
  const d = aFecha(valor)
  if (!d) return 'Antes'
  const dia = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const hoy = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate()).getTime()
  const dias = Math.round((hoy - dia) / 86400000)
  if (dias <= 0) return 'Hoy'
  if (dias === 1) return 'Ayer'
  if (dias < 7) return 'Últimos 7 días'
  const mes = mesNombre(d.getMonth() + 1)
  return d.getFullYear() === ahora.getFullYear() ? mes : `${mes} ${d.getFullYear()}`
}

/* Agrupa en ese orden, conservando el orden de llegada dentro de cada grupo. */
export function agruparPorFecha<T extends { created_at: string }>(items: readonly T[], ahora: Date = new Date()): { titulo: string; items: T[] }[] {
  const out: { titulo: string; items: T[] }[] = []
  for (const it of items) {
    const g = grupoFecha(it.created_at, ahora)
    const ultimo = out.at(-1)
    if (ultimo && ultimo.titulo === g) ultimo.items.push(it)
    else out.push({ titulo: g, items: [it] })
  }
  return out
}

/* Hora de la fila: «HH:MM» si es de hoy, «Ayer» o «dd/mm/aa». */
export function horaAviso(valor: string, ahora: Date = new Date()) {
  const d = aFecha(valor)
  if (!d) return ''
  const g = grupoFecha(valor, ahora)
  if (g === 'Hoy') return `${dos(d.getHours())}:${dos(d.getMinutes())}`
  if (g === 'Ayer') return 'Ayer'
  return `${dos(d.getDate())}/${dos(d.getMonth() + 1)}/${String(d.getFullYear()).slice(2)}`
}

/* «🕒 09/10 09:00» de un aviso pospuesto. */
export function pospuestoHasta(valor: string | null) {
  const d = aFecha(valor)
  return d ? `${dos(d.getDate())}/${dos(d.getMonth() + 1)} ${dos(d.getHours())}:${dos(d.getMinutes())}` : ''
}

/* Fecha relativa de las actas: «hace un momento», «hace 5 minutos», «hoy»,
   «ayer», «hace 3 días», «hace 2 semanas», «hace 1 mes», «hace 2 años». */
export function haceActa(valor: string, ahora: Date = new Date()) {
  const d = aFecha(valor)
  if (!d) return ''
  const seg = Math.max(0, Math.round((ahora.getTime() - d.getTime()) / 1000))
  if (seg < 60) return 'hace un momento'
  if (seg < 3600) {
    const m = Math.floor(seg / 60)
    return `hace ${m} ${m === 1 ? 'minuto' : 'minutos'}`
  }
  const g = grupoFecha(valor, ahora)
  if (g === 'Hoy') {
    const h = Math.floor(seg / 3600)
    return h < 6 ? `hace ${h} ${h === 1 ? 'hora' : 'horas'}` : 'hoy'
  }
  if (g === 'Ayer') return 'ayer'
  const dias = Math.round((new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate()).getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86400000)
  if (dias < 7) return `hace ${dias} días`
  if (dias < 30) {
    const s = Math.floor(dias / 7)
    return `hace ${s} ${s === 1 ? 'semana' : 'semanas'}`
  }
  if (dias < 365) {
    const m = Math.floor(dias / 30)
    return `hace ${m} ${m === 1 ? 'mes' : 'meses'}`
  }
  const a = Math.floor(dias / 365)
  return `hace ${a} ${a === 1 ? 'año' : 'años'}`
}

const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']

/* «23 sep 2026, 18:40» (título con la fecha exacta). */
export function fechaExacta(valor: string) {
  const d = aFecha(valor)
  if (!d) return ''
  return `${d.getDate()} ${MESES_CORTOS[d.getMonth()]} ${d.getFullYear()}, ${dos(d.getHours())}:${dos(d.getMinutes())}`
}

/* Cosas de cada día del mes (calendario del inicio), en el orden en que llegan. */
export function porDia<T extends { dia: string }>(items: readonly T[]): Map<string, T[]> {
  const m = new Map<string, T[]>()
  for (const it of items) {
    const k = it.dia.slice(0, 10)
    m.set(k, [...(m.get(k) ?? []), it])
  }
  return m
}

/* «Jue 8 Octubre» (píldora del calendario). */
export function hoyLargo(d: Date = new Date()) {
  return `${['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][d.getDay()]} ${d.getDate()} ${mesNombre(d.getMonth() + 1)}`
}

/* Título de un pop-up de aviso: «laura · te ha asignado esta tarea». */
export function tituloPopup(a: { actor: string; titulo: string }) {
  return a.actor ? `${a.actor} · ${a.titulo}` : a.titulo
}

/* Subtítulo: la tarea (o el cuerpo) y «· +N más» si llegaron varios a la vez. */
export function subtituloPopup(a: { tarea: string; cuerpo: string }, mas: number) {
  const base = a.tarea || a.cuerpo
  return mas > 0 ? `${base}${base ? ' · ' : ''}+${mas} más` : base
}

/* ---------- Pulso del inicio ---------- */

export type TarjetaPulso = {
  id: 'tickets' | 'crm' | 'cobro' | 'vencidas' | 'chat'
  label: string
  valor: string
  sub: string
  to: string
  /* Pide atención (se tiñe): algo atrasado, vencido o sin asignar. */
  alerta: boolean
}

const plural = (n: number, uno: string, varios: string) => `${n} ${n === 1 ? uno : varios}`

/* Tarjetas del pulso, solo las de los módulos que la persona puede ver. */
export function tarjetasPulso(p: Inicio['pulso']): TarjetaPulso[] {
  const out: TarjetaPulso[] = []
  if (p.tickets) {
    const { abiertos, sin_asignar, mios } = p.tickets
    out.push({
      id: 'tickets',
      label: 'Tickets',
      valor: String(abiertos),
      sub: abiertos === 0 ? 'Soporte al día' : [sin_asignar ? `${sin_asignar} sin asignar` : '', mios ? plural(mios, 'tuyo', 'tuyos') : ''].filter(Boolean).join(' · ') || 'Todos asignados',
      to: '/soporte',
      alerta: sin_asignar > 0,
    })
  }
  if (p.crm_hoy) {
    const { total, atrasados } = p.crm_hoy
    out.push({
      id: 'crm',
      label: 'Contactar hoy',
      valor: String(total),
      sub: total === 0 ? 'Sin seguimientos para hoy' : atrasados ? plural(atrasados, 'atrasado', 'atrasados') : 'Todos de hoy',
      to: '/crm/reporting',
      alerta: atrasados > 0,
    })
  }
  if (p.cobros) {
    const { pendiente, vencidas } = p.cobros
    out.push({
      id: 'cobro',
      label: 'Por cobrar',
      valor: pendiente.total !== null ? eur(pendiente.total / 100) : String(pendiente.n),
      sub: pendiente.n === 0 ? 'Nada por cobrar' : plural(pendiente.n, 'factura', 'facturas'),
      to: '/finanzas/clientes',
      alerta: false,
    })
    out.push({
      id: 'vencidas',
      label: 'Vencidas',
      valor: String(vencidas.n),
      sub: vencidas.n === 0 ? 'Ninguna vencida' : vencidas.total !== null ? eur(vencidas.total / 100) : 'Revisa los cobros',
      to: '/finanzas/clientes',
      alerta: vencidas.n > 0,
    })
  }
  if (p.chat_no_leidos !== null) {
    out.push({
      id: 'chat',
      label: 'Sin leer',
      valor: String(p.chat_no_leidos),
      sub: p.chat_no_leidos === 0 ? 'Chat al día' : 'En el chat del equipo',
      to: '/chat',
      alerta: false,
    })
  }
  return out
}
