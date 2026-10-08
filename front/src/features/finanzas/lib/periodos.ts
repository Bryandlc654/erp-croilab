import { isoDia } from '../../../shared/lib/formato'

/* Meses 'AAAA-MM' y períodos de facturación. */

export function ymDe(d: Date = new Date()) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

export function sumarMeses(ym: string, n: number) {
  const [y, m] = ym.split('-').map(Number)
  return ymDe(new Date(y, m - 1 + n, 1))
}

export function esMes(v: string | null | undefined): v is string {
  return !!v && /^\d{4}-(0[1-9]|1[0-2])$/.test(v)
}

export function ultimoDia(ym: string) {
  const [y, m] = ym.split('-').map(Number)
  return isoDia(new Date(y, m, 0))
}

export type Periodo = 'vista' | 'vencido' | 'ninguno'

/* Atajos del editor: «Mes vista» = el mes actual completo, «Mes vencido» = el
   anterior. Corrige dos rarezas del antiguo: «hasta» es el ÚLTIMO día del mes
   (no el día 1 del siguiente) y la fecha de expedición no se mueve al día 1
   (rompería el orden de la numeración: se expide el día que se emite). */
export function periodoRapido(tipo: Periodo, hoy: Date = new Date()): { ini: string | null; fin: string | null } {
  if (tipo === 'ninguno') return { ini: null, fin: null }
  const actual = ymDe(hoy)
  const ym = tipo === 'vista' ? actual : sumarMeses(actual, -1)
  return { ini: `${ym}-01`, fin: ultimoDia(ym) }
}

/* ¿Qué atajo corresponde a un período ya puesto? (para marcar el segmentado). */
export function periodoDe(ini: string | null, fin: string | null, hoy: Date = new Date()): Periodo | null {
  if (!ini && !fin) return 'ninguno'
  for (const t of ['vista', 'vencido'] as const) {
    const p = periodoRapido(t, hoy)
    if (p.ini === ini && p.fin === fin) return t
  }
  return null
}

/* «2026-03-14» → «14/03/2026» (en la hoja de la factura, año completo). */
export function fechaLargaNum(iso: string | null | undefined) {
  if (!iso) return '—'
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : '—'
}

export function hoyIso() {
  return isoDia(new Date())
}
