import { isoDia } from './formato'

/* Lo que se escribe a mano en un campo de fecha (§4.15): «d/m/aa», «d-m-aaaa»,
   «d.m.aa», «d/m» (este año) o ya en ISO. Años de dos cifras → 20xx.
   Devuelve 'AAAA-MM-DD' o null si no es una fecha real (31/02 no vale). */
export function parsearFecha(texto: string, hoy: Date = new Date()): string | null {
  const t = texto.trim()
  if (!t) return null
  const iso = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(t)
  let d: number, m: number, y: number
  if (iso) {
    y = Number(iso[1])
    m = Number(iso[2])
    d = Number(iso[3])
  } else {
    const p = /^(\d{1,2})\s*[/.-]\s*(\d{1,2})(?:\s*[/.-]\s*(\d{2}|\d{4}))?$/.exec(t)
    if (!p) return null
    d = Number(p[1])
    m = Number(p[2])
    y = p[3] === undefined ? hoy.getFullYear() : Number(p[3])
    if (y < 100) y += 2000
  }
  if (m < 1 || m > 12 || d < 1 || d > 31) return null
  const f = new Date(y, m - 1, d)
  if (f.getFullYear() !== y || f.getMonth() !== m - 1 || f.getDate() !== d) return null
  return isoDia(f)
}

export type DiaCalendario = { iso: string; dia: number; delMes: boolean }

/* Rejilla del mes con la semana empezando en LUNES: completa por delante con
   días del mes anterior y por detrás hasta cerrar la última semana. */
export function diasCalendario(anio: number, mes0: number): DiaCalendario[] {
  const primero = new Date(anio, mes0, 1)
  const desfase = (primero.getDay() + 6) % 7 // lunes = 0
  const enMes = new Date(anio, mes0 + 1, 0).getDate()
  const total = Math.ceil((desfase + enMes) / 7) * 7
  const out: DiaCalendario[] = []
  for (let i = 0; i < total; i++) {
    const f = new Date(anio, mes0, 1 - desfase + i)
    out.push({ iso: isoDia(f), dia: f.getDate(), delMes: f.getMonth() === mes0 })
  }
  return out
}

/* Suma días a una fecha ISO. */
export function sumarDias(iso: string, n: number) {
  const [y, m, d] = iso.split('-').map(Number)
  return isoDia(new Date(y, m - 1, d + n))
}

/* ¿Vence pronto o ya venció? Para colorear fechas límite (≤2 días / pasada). */
export function tonoVencimiento(iso: string | null | undefined, hoy: Date = new Date()): 'late' | 'soon' | null {
  if (!iso) return null
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number)
  const f = new Date(y, m - 1, d).getTime()
  const h = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate()).getTime()
  const dias = Math.round((f - h) / 86400000)
  if (dias < 0) return 'late'
  if (dias <= 2) return 'soon'
  return null
}
