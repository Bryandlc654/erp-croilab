import { mesNombre } from '../../shared/lib/formato'
import type { ContenidoEditable, DatosPortal, Metrica, Secciones } from './schemas'

/* Lógica pura del portal (se prueba en logica.test.ts). */

/* ---------- Meses con año (como Portal\Meses del backend) ---------- */

const NOMBRES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

/* «2026-06», «Junio 2026», «junio de 2026» → la que dice; «Junio» → el último
   junio no posterior a `ref`. null si no es un mes («General»). */
export function claveMes(etiqueta: string, ref: Date): string | null {
  const t = etiqueta
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
  if (!t) return null
  const iso = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(t)
  if (iso) return `${iso[1]}-${iso[2]}`
  const m = /^([a-z]+)\.?(?:\s+(?:de\s+|del\s+)?(\d{4}))?$/.exec(t)
  if (!m) return null
  let n = m[1] === 'setiembre' || m[1] === 'set' ? 9 : 0
  if (!n) n = NOMBRES.findIndex((c) => c === m[1] || (m[1].length >= 3 && c.startsWith(m[1]))) + 1
  if (!n) return null
  let anio = m[2] ? Number(m[2]) : ref.getFullYear()
  if (!m[2] && n > ref.getMonth() + 1) anio--
  return `${anio}-${String(n).padStart(2, '0')}`
}

export const etiquetaMes = (clave: string) => `${mesNombre(Number(clave.slice(5, 7)))} ${clave.slice(0, 4)}`
export const nombreMes = (clave: string) => mesNombre(Number(clave.slice(5, 7)))
export const mesCorto = (clave: string) => nombreMes(clave).slice(0, 3)

export function mesAnterior(clave: string) {
  const a = Number(clave.slice(0, 4))
  const m = Number(clave.slice(5, 7))
  return m === 1 ? `${a - 1}-12` : `${a}-${String(m - 1).padStart(2, '0')}`
}

/* Los meses alrededor de hoy para elegir en el editor («Octubre 2026»…). */
export function mesesCercanos(hoy: Date, atras = 12, adelante = 2): string[] {
  const out: string[] = []
  for (let i = adelante; i >= -atras; i--) {
    const d = new Date(hoy.getFullYear(), hoy.getMonth() + i, 1)
    out.push(`${mesNombre(d.getMonth() + 1)} ${d.getFullYear()}`)
  }
  return out
}

/* ---------- Variaciones ---------- */

export type Delta = { tipo: 'sube' | 'baja' | 'igual' | 'nuevo' | 'partida'; pct: number }

/* Variación respecto al mes anterior: «partida» si no hay mes anterior, «nuevo» si era 0. */
export function delta(actual: number, anterior: number | null): Delta {
  if (anterior === null) return { tipo: 'partida', pct: 0 }
  if (anterior === 0) return actual > 0 ? { tipo: 'nuevo', pct: 0 } : { tipo: 'igual', pct: 0 }
  const pct = Math.round(((actual - anterior) / anterior) * 100)
  if (pct === 0) return { tipo: 'igual', pct: 0 }
  return { tipo: pct > 0 ? 'sube' : 'baja', pct }
}

/* «▲ +12%» / «▼ -5%» / «igual» / «▲ nuevo» / «mes de partida». */
export function textoDelta(d: Delta) {
  switch (d.tipo) {
    case 'sube':
      return `▲ +${d.pct}%`
    case 'baja':
      return `▼ ${d.pct}%`
    case 'igual':
      return 'igual'
    case 'nuevo':
      return '▲ nuevo'
    default:
      return 'mes de partida'
  }
}

/* Métrica de un mes y la del mes de calendario anterior (no la anterior de la lista: puede faltar un mes). */
export function conAnterior(metricas: Metrica[], clave: string): { actual: Metrica | null; anterior: Metrica | null } {
  const actual = metricas.find((m) => m.clave === clave) ?? null
  const anterior = metricas.find((m) => m.clave === mesAnterior(clave)) ?? null
  return { actual, anterior }
}

/* Suma de todos los meses («Global»). El CTR global sale de clics/apariciones, no de sumar porcentajes. */
export function global(metricas: Metrica[]): Metrica {
  const s = { ll: 0, wa: 0, fo: 0, vi: 0, ap: 0 }
  const src: Record<string, number> = {}
  const geo: Record<string, number> = {}
  for (const m of metricas) {
    s.ll += m.ll
    s.wa += m.wa
    s.fo += m.fo
    s.vi += m.vi
    s.ap += m.ap
    for (const [k, v] of Object.entries(m.src)) src[k] = (src[k] ?? 0) + v
    for (const [k, v] of Object.entries(m.geo)) geo[k] = (geo[k] ?? 0) + v
  }
  return { clave: 'global', etiqueta: 'Global', mes: 'Global', ...s, ctr: s.ap ? Math.round((s.vi / s.ap) * 1000) / 10 : 0, total: s.ll + s.wa + s.fo, src, geo }
}

/* ---------- Formato ---------- */

/* Céntimos → «1.234,56 €». */
export function euros(c: number) {
  const neg = c < 0
  const abs = Math.abs(c)
  const ent = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  return `${neg ? '-' : ''}${ent},${String(abs % 100).padStart(2, '0')} €`
}

export const miles = (n: number) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')
export const pct1 = (n: number) => n.toFixed(1).replace('.', ',')

/* ---------- Secciones y servicios ---------- */

export type Vista = 'inicio' | 'metricas' | 'tareas' | 'progreso' | 'reuniones' | 'soporte' | 'informes' | 'facturas' | 'metodo' | 'accesos' | 'plan'

/* Las vistas que no dependen del tipo de cliente siempre se ven. */
export function vistaVisible(v: Vista, s: Secciones): boolean {
  switch (v) {
    case 'metricas':
      return s.metricas
    case 'progreso':
      return s.progreso
    case 'informes':
      return s.informes
    case 'metodo':
      return s.como
    case 'accesos':
      return s.accesos
    case 'plan':
      return s.plan
    default:
      return true
  }
}

const ALIAS: Record<string, string[]> = {
  meta: ['meta', 'meta ads'],
  'meta ads': ['meta', 'meta ads'],
  'tiendas online': ['tiendas online', 'tienda online'],
  'tienda online': ['tiendas online', 'tienda online'],
}
const nombresDe = (s: string) => ALIAS[s.trim().toLowerCase()] ?? [s.trim().toLowerCase()]

/* null = el cliente no tiene restringidos los servicios (ve todos). */
export function servicioContratado(servicios: string[] | null, nombre: string) {
  if (servicios === null) return true
  const buscados = nombresDe(nombre)
  return servicios.some((s) => buscados.includes(s.trim().toLowerCase()))
}

/* Vídeo de un servicio (catálogo por nombre o su alias) o el general. */
export function videoDe(videos: Record<string, string>, nombre: string, general: string) {
  const buscados = nombresDe(nombre)
  for (const [k, v] of Object.entries(videos)) if (buscados.includes(k.trim().toLowerCase()) && v) return v
  return general
}

/* ---------- Buscador ---------- */

const PALABRAS: [Vista, string[]][] = [
  ['metricas', ['metric', 'numero', 'número', 'conversion', 'conversión', 'llamada', 'whatsapp', 'formulario', 'google', 'visita', 'visibilidad', 'ctr']],
  ['tareas', ['progreso', 'trabajo', 'tarea', 'hecho', 'avance']],
  ['informes', ['informe', 'report', 'pdf']],
  ['metodo', ['metodo', 'método', 'como', 'cómo', 'servicio', 'video', 'vídeo', 'seo', 'web', 'sem', 'cro', 'meta', 'tienda']],
  ['accesos', ['acceso', 'contraseña', 'credencial', 'contacto', 'ayuda', 'email', 'correo']],
  ['plan', ['plan', 'precio', 'contrato', 'mensual', 'incluye']],
  ['inicio', ['inicio', 'resumen', 'home', 'portada']],
  ['facturas', ['factura', 'pago', 'cobro']],
  ['soporte', ['soporte', 'ticket', 'mensaje', 'duda']],
  ['reuniones', ['reunion', 'reunión', 'cita', 'llamar']],
]

export type Destino = { tipo: 'vista'; vista: Vista } | { tipo: 'servicio'; nombre: string } | null

/* Enter en «Buscar en tu proyecto»: servicio contratado → su ficha; palabra clave → sección; mes o título de informe → Informes. */
export function buscar(q: string, d: Pick<DatosPortal, 'secciones' | 'servicios' | 'informes'>, servicios: string[]): Destino {
  const t = q.trim().toLowerCase()
  if (!t) return null
  if (d.secciones.como) {
    const s = servicios.find((n) => n.toLowerCase().includes(t) && servicioContratado(d.servicios, n))
    if (s) return { tipo: 'servicio', nombre: s }
  }
  for (const [vista, ps] of PALABRAS) {
    if (ps.some((p) => t.includes(p)) && vistaVisible(vista, d.secciones)) return { tipo: 'vista', vista }
  }
  if (d.secciones.informes && d.informes.some((i) => i.etiqueta.toLowerCase().includes(t) || i.titulo.toLowerCase().includes(t))) return { tipo: 'vista', vista: 'informes' }
  return null
}

/* ---------- Calculadora de ingresos ---------- */

export function estimarIngresos(contactos: number, pctCierre: number, ticket: number) {
  const clientes = Math.max(0, contactos) * (Math.max(0, Math.min(100, pctCierre)) / 100)
  return { clientes, ingresos: clientes * Math.max(0, ticket) }
}

/* ---------- Editor en vivo: lo editado, pintado como lo verá el cliente ---------- */

export function aplicarEdicion(d: DatosPortal, c: ContenidoEditable, hoy: Date): DatosPortal {
  const refTrabajo = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 1)
  const progreso = new Map<string, DatosPortal['progreso'][number]>()
  for (const m of c.tareas) {
    const clave = claveMes(m.mes, refTrabajo)
    const id = clave ?? 'x:' + m.mes.toLowerCase()
    const previo = progreso.get(id) ?? { clave, etiqueta: clave ? etiquetaMes(clave) : m.mes || 'General', completado: [], pendiente: [] }
    previo.completado.push(...m.completado.filter((t) => t.t.trim()))
    previo.pendiente.push(...m.pendiente.filter((t) => t.t.trim()))
    progreso.set(id, previo)
  }
  const informes = [...c.informes]
    .reverse()
    .filter((i) => i.titulo.trim() || i.texto.trim())
    .map((i) => {
      const clave = claveMes(i.mes, refTrabajo)
      return { clave, mes: i.mes, etiqueta: clave ? etiquetaMes(clave) : i.mes || 'General', titulo: i.titulo || 'Informe', texto: i.texto, url: /^https?:\/\//i.test(i.url) ? i.url : '' }
    })
  const actual = claveMes(c.actual, refTrabajo) ?? d.cliente.actual
  return {
    ...d,
    cliente: { ...d.cliente, name: c.name, saludo: c.saludo.trim() || c.name, iniciales: c.iniciales.trim() || 'CL', username: c.username, actual, actual_etiqueta: etiquetaMes(actual) },
    estado: { ...c.estado, fases: c.estado.fases.filter((f) => f.t.trim()) },
    plan: { ...c.plan, items: c.plan.items.filter((i) => i.n.trim() || i.t.trim()), detalle: c.plan.detalle.filter((i) => i.h.trim() || i.p.trim()) },
    accesos: c.accesos.filter((a) => a.b.trim()).map((a) => ({ ...a, u: /^https?:\/\//i.test(a.u) ? a.u : '' })),
    servicios: c.servicios,
    looker: /^https:\/\/(lookerstudio|datastudio)\.google\.com\//i.test(c.looker) ? c.looker : '',
    progreso: ordenarPorClave([...progreso.values()], true),
    informes: ordenarPorClave(informes, true),
  }
}

/* Con mes primero (de más nuevo a más viejo si desc), sin mes («General») al final. */
export function ordenarPorClave<T extends { clave: string | null }>(filas: T[], desc = false): T[] {
  const con = filas.filter((f) => f.clave !== null).sort((a, b) => (desc ? (b.clave as string).localeCompare(a.clave as string) : (a.clave as string).localeCompare(b.clave as string)))
  return [...con, ...filas.filter((f) => f.clave === null)]
}
