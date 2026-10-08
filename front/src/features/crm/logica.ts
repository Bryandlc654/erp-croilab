import { eur0, fechaCorta, numero } from '../../shared/lib/formato'
import { FASE_DESCONOCIDA } from '../../shared/lib/paletas'
import type { Persona } from '../../shared/schemas'
import type { Catalogos, Fase, FiltrosGuardados, Negocio } from './schemas'

/* Lógica pura del CRM (sin React): filtros ⇄ URL, textos de filtros y
   condiciones, colores de fase, avisos de negocios parados y enlaces de
   contacto. Probada en logica.test.ts. */

export type Filtros = {
  q: string
  sector: string
  origen: string
  fase: string
  servicio: string
  prop: string
  tag: string
  vmin: string
  vmax: string
  fdesde: string
  fhasta: string
  quick: string
  sort: string
  dir: string
}

export const CLAVES_FILTRO: (keyof Filtros)[] = ['q', 'sector', 'origen', 'fase', 'servicio', 'prop', 'tag', 'vmin', 'vmax', 'fdesde', 'fhasta', 'quick', 'sort', 'dir']
/* Los que cuentan en el botón «Filtros» (ni la búsqueda ni las píldoras). */
export const AVANZADOS: (keyof Filtros)[] = ['sector', 'origen', 'fase', 'servicio', 'prop', 'tag', 'vmin', 'vmax', 'fdesde', 'fhasta']

export const RAPIDOS = [
  { value: '', label: 'Todos' },
  { value: 'sin_contactar', label: 'Sin contactar' },
  { value: 'act7', label: 'Sin actividad +7 días' },
  { value: 'act30', label: '+30 días' },
  { value: 'vencidas', label: 'Acción vencida' },
  { value: 'perdido', label: 'Cerrado perdido' },
]

export const ORDENABLES = ['nombre', 'empresa', 'sector', 'valor', 'fase', 'ult', 'prox', 'creado'] as const

/** Filtros de la URL (?sector=…&quick=…), como en el antiguo: compartibles y con «atrás». */
export function filtrosDeParams(p: URLSearchParams): Filtros {
  const f = {} as Filtros
  for (const k of CLAVES_FILTRO) f[k] = (p.get(k) ?? '').trim()
  return f
}

/** Filtros → URLSearchParams sin vacíos (conserva otras claves que hubiera, p. ej. de la ficha). */
export function paramsDeFiltros(f: Partial<Filtros>, base?: URLSearchParams): URLSearchParams {
  const n = new URLSearchParams(base)
  for (const k of CLAVES_FILTRO) {
    const v = (f[k] ?? '').trim()
    if (v) n.set(k, v)
    else n.delete(k)
  }
  return n
}

export function contarAvanzados(f: Partial<Filtros>) {
  return AVANZADOS.filter((k) => (f[k] ?? '') !== '').length
}

/** Filtros guardados (vista) → los de la URL. */
export function filtrosDeVista(v: FiltrosGuardados): Partial<Filtros> {
  const f: Partial<Filtros> = {}
  for (const k of CLAVES_FILTRO) {
    const x = v[k]
    if (x !== undefined && x !== null && String(x) !== '') f[k] = String(x)
  }
  return f
}

/** Los filtros puestos ahora, para guardarlos como vista. */
export function vistaDeFiltros(f: Filtros): Record<string, string> {
  const out: Record<string, string> = {}
  for (const k of CLAVES_FILTRO) if (f[k]) out[k] = f[k]
  return out
}

export type ChipFiltro = { key: keyof Filtros | 'rango'; label: string; quitar: (keyof Filtros)[] }

/** Chips de filtros activos: ««texto»», «Sector: X», «Embudo: X», «≥ N €», «Desde …». */
export function chipsFiltros(f: Filtros, cat: Catalogos | undefined, equipo: Persona[] | undefined): ChipFiltro[] {
  const c: ChipFiltro[] = []
  if (f.q) c.push({ key: 'q', label: `«${f.q}»`, quitar: ['q'] })
  if (f.sector) c.push({ key: 'sector', label: `Sector: ${f.sector}`, quitar: ['sector'] })
  if (f.origen) c.push({ key: 'origen', label: `Origen: ${f.origen}`, quitar: ['origen'] })
  if (f.fase) c.push({ key: 'fase', label: `Embudo: ${nombreFase(cat?.fases, f.fase)}`, quitar: ['fase'] })
  if (f.servicio) c.push({ key: 'servicio', label: `Servicio: ${f.servicio}`, quitar: ['servicio'] })
  if (f.prop) c.push({ key: 'prop', label: `Propietario: ${f.prop === 'sin' ? 'Sin propietario' : (equipo?.find((p) => String(p.id) === f.prop)?.username ?? `#${f.prop}`)}`, quitar: ['prop'] })
  if (f.tag) c.push({ key: 'tag', label: `Etiqueta: ${cat?.etiquetas.find((t) => String(t.id) === f.tag)?.nombre ?? `#${f.tag}`}`, quitar: ['tag'] })
  if (f.vmin) c.push({ key: 'vmin', label: `≥ ${importeTexto(f.vmin)}`, quitar: ['vmin'] })
  if (f.vmax) c.push({ key: 'vmax', label: `≤ ${importeTexto(f.vmax)}`, quitar: ['vmax'] })
  if (f.fdesde) c.push({ key: 'fdesde', label: `Desde ${fechaCorta(f.fdesde)}`, quitar: ['fdesde'] })
  if (f.fhasta) c.push({ key: 'fhasta', label: `Hasta ${fechaCorta(f.fhasta)}`, quitar: ['fhasta'] })
  return c
}

/** «1.234,5» / «1234.5» / «1.200 €» → número (como num_es del antiguo); null si no se entiende. */
export function leerImporte(texto: string): number | null {
  let s = texto.replace(/[^0-9,.-]/g, '')
  if (s === '' || s === '-') return null
  const neg = s.startsWith('-')
  s = s.replace(/-/g, '')
  const uc = s.lastIndexOf(',')
  const up = s.lastIndexOf('.')
  const ult = Math.max(uc, up)
  let ent = s
  let dec = ''
  if (ult >= 0) {
    ent = s.slice(0, ult)
    dec = s.slice(ult + 1)
    const dos = uc >= 0 && up >= 0
    if (!dos && dec.length === 3 && ent.replace(/0/g, '') !== '') {
      ent += dec
      dec = ''
    }
    ent = ent.replace(/[^0-9]/g, '')
    dec = dec.replace(/[^0-9]/g, '')
  }
  if (ent === '' && dec === '') return null
  const n = Number(`${ent || '0'}.${dec || '0'}`)
  if (!Number.isFinite(n)) return null
  return Math.round((neg ? -n : n) * 100) / 100
}

/** «12500.00» → «12.500 €». */
export function importeTexto(v: string | number | null | undefined) {
  if (v === null || v === undefined || v === '') return ''
  const n = typeof v === 'number' ? v : leerImporte(v)
  return n === null ? String(v) : `${numero(n, Number.isInteger(n) ? 0 : 2)} €`
}

/** Euros sin céntimos, o «—» si no se pueden ver (sin `ver.importes`). */
export function euros(n: number | null | undefined) {
  return n === null || n === undefined ? '—' : eur0(n)
}

/** Valor de celda «12.500» (sin €), con céntimos solo si los tiene. */
export function importeCelda(v: number | null | undefined) {
  if (v === null || v === undefined) return ''
  return numero(v, Number.isInteger(v) ? 0 : 2)
}

export function faseDe(fases: Fase[] | undefined, slug: string): Fase | undefined {
  return fases?.find((f) => f.slug === slug)
}

export function nombreFase(fases: Fase[] | undefined, slug: string) {
  return faseDe(fases, slug)?.nombre ?? slug
}

export function colorFase(fases: Fase[] | undefined, slug: string) {
  return faseDe(fases, slug)?.color ?? FASE_DESCONOCIDA
}

/** «Sector: X · Embudo: Y · ≥ 2.000 €» de las condiciones de una lista activa. */
export function textoCondiciones(c: FiltrosGuardados, cat: Catalogos | undefined, equipo: Persona[] | undefined) {
  const partes: string[] = []
  if (c.sector) partes.push(`Sector: ${c.sector}`)
  if (c.origen) partes.push(`Origen: ${c.origen}`)
  if (c.fase) partes.push(`Embudo: ${nombreFase(cat?.fases, c.fase)}`)
  if (c.servicio) partes.push(`Servicio: ${c.servicio}`)
  if (c.prop !== undefined && c.prop !== '') partes.push(`Propietario: ${c.prop === 'sin' ? 'Sin propietario' : (equipo?.find((p) => p.id === Number(c.prop))?.username ?? `#${c.prop}`)}`)
  if (c.quick === 'sin_contactar') partes.push('Sin contactar')
  if (c.quick === 'act30') partes.push('Sin actividad +30 días')
  if (c.vmin) partes.push(`≥ ${importeTexto(c.vmin)}`)
  if (c.vmax) partes.push(`≤ ${importeTexto(c.vmax)}`)
  if (c.q) partes.push(`«${c.q}»`)
  return partes.join(' · ')
}

/** Aviso de negocio parado: ≥14 días naranja, ≥30 rojo (solo fases abiertas). */
export function tonoParado(n: Pick<Negocio, 'dias_en_fase' | 'tipo_fase'>): 'aviso' | 'peligro' | null {
  if (n.tipo_fase !== 'abierta') return null
  if (n.dias_en_fase >= 30) return 'peligro'
  if (n.dias_en_fase >= 14) return 'aviso'
  return null
}

/** Columnas del tablero: una por fase, en su orden, con su total. */
export function columnasEmbudo(fases: Fase[], negocios: Negocio[]) {
  return fases.map((f) => {
    const items = negocios.filter((n) => n.fase === f.slug)
    const total = items.reduce((s, n) => s + Math.round((n.valor ?? 0) * 100), 0) / 100
    return { id: f.slug, title: f.nombre, color: f.color, items, total: eur0(total) }
  })
}

/** Dígitos para wa.me: se antepone 34 a los números españoles sin prefijo. */
export function enlaceWhatsapp(telefono: string) {
  const d = telefono.replace(/\D/g, '')
  if (!d) return null
  return `https://wa.me/${d.length === 9 ? '34' + d : d}`
}

export function enlaceGmail(email: string) {
  return email ? `https://mail.google.com/mail/?view=cm&to=${encodeURIComponent(email)}` : null
}

export function enlaceTel(tel: string) {
  const t = tel.replace(/[^0-9+]/g, '')
  return t ? `tel:${t}` : null
}

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic']
/** '2026-05' → «may» (y «may 25» si no es del año en curso). */
export function mesCorto(ym: string, hoy = new Date()) {
  const m = /^(\d{4})-(\d{2})/.exec(ym)
  if (!m) return ym
  const nombre = MESES[Number(m[2]) - 1] ?? ym
  return Number(m[1]) === hoy.getFullYear() ? nombre : `${nombre} ${m[1].slice(2)}`
}

/** Rangos rápidos del dashboard: este mes, últimos 3 meses, este año. */
export function rangoRapido(clave: 'mes' | 'tres' | 'anio', hoy = new Date()): { desde: string; hasta: string } {
  const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const hasta = iso(hoy)
  if (clave === 'mes') return { desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta }
  if (clave === 'tres') return { desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 2, 1)), hasta }
  return { desde: `${hoy.getFullYear()}-01-01`, hasta }
}

/** Nombre del fichero CSV → descarga en el navegador (el servidor ya pone el BOM y neutraliza fórmulas). */
export function descargarTexto(nombre: string, texto: string, tipo = 'text/csv;charset=utf-8') {
  const url = URL.createObjectURL(new Blob([texto], { type: tipo }))
  const a = document.createElement('a')
  a.href = url
  a.download = nombre
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** Campos a los que se puede llevar una columna del CSV. */
export const CAMPOS_IMPORTAR: { value: string; label: string }[] = [
  { value: '', label: '— No importar —' },
  { value: 'nombre', label: 'Nombre' },
  { value: 'empresa', label: 'Empresa' },
  { value: 'sector', label: 'Sector' },
  { value: 'email', label: 'Email' },
  { value: 'telefono', label: 'Teléfono' },
  { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'origen_lead', label: 'Origen' },
  { value: 'servicios', label: 'Servicios' },
  { value: 'valor', label: 'Valor' },
  { value: 'fase', label: 'Fase' },
  { value: 'propietario', label: 'Propietario' },
]

/** Cambiar el campo de una columna: si ya lo tenía otra, esa queda «No importar». */
export function cambiarMapeo(mapeo: string[], col: number, campo: string) {
  return mapeo.map((c, i) => (i === col ? campo : campo !== '' && c === campo ? '' : c))
}

/** Siguiente/anterior contacto de la lista filtrada (flechas de la ficha). */
export function vecino(ids: number[], actual: number, paso: 1 | -1) {
  const i = ids.indexOf(actual)
  if (i < 0) return null
  return ids[i + paso] ?? null
}
