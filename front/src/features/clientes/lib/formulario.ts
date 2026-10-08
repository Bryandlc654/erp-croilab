import type { Acceso, DatosCliente, EstadoProyecto, Fase, ProgresoMes, TipoAcceso } from '../schemas'

/* Estado del formulario de alta/edición (edit.php) y su paso al cuerpo de la
   API. Las listas repetibles llevan una `key` estable para React. */

export const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']

let siguiente = 0
export function nuevaKey() {
  siguiente += 1
  return `f${siguiente}`
}

export type FilaFase = Fase & { key: string }
export type FilaItem = { key: string; n: string; t: string }
export type FilaDetalle = { key: string; h: string; p: string }
export type FilaAcceso = Acceso & { key: string }
export type GrupoProgreso = 'completado' | 'pendiente'
export type FilaProgreso = { key: string; mes: string; estado: GrupoProgreso; t: string; d: string }

export type FormCliente = {
  name: string
  saludo: string
  iniciales: string
  username: string
  password: string
  actual: string
  login_email: string
  tipo_id: number | null
  conversiones: boolean
  activo: boolean
  fact_nombre: string
  fact_nif: string
  fact_dir: string
  fact_email: string
  est_nombre: string
  est_etiqueta: string
  est_siguiente: string
  fases: FilaFase[]
  plan_resumen: string
  items: FilaItem[]
  detalle: FilaDetalle[]
  accesos: FilaAcceso[]
  progreso: FilaProgreso[]
}

/* Fases con las que nace un cliente nuevo (edit.php:48-53). */
export const FASES_POR_DEFECTO: Fase[] = [
  { t: 'Auditoría', s: 'y arranque', estado: 'done' },
  { t: 'Base técnica', s: 'y contenidos', estado: 'done' },
  { t: 'Crecimiento', s: 'y captación', estado: 'now' },
  { t: 'Consolidación', s: 'y escala', estado: '' },
]

export const filaFase = (f: Partial<Fase> = {}): FilaFase => ({ key: nuevaKey(), t: '', s: '', estado: '', ...f })
export const filaItem = (): FilaItem => ({ key: nuevaKey(), n: '', t: '' })
export const filaDetalle = (): FilaDetalle => ({ key: nuevaKey(), h: '', p: '' })
export const filaAcceso = (tipo: TipoAcceso = 'generic'): FilaAcceso => ({ key: nuevaKey(), b: '', s: '', u: '', tipo })
export const filaProgreso = (mes = '', estado: GrupoProgreso = 'completado'): FilaProgreso => ({ key: nuevaKey(), mes, estado, t: '', d: '' })

export function formVacio(): FormCliente {
  return {
    name: '',
    saludo: '',
    iniciales: '',
    username: '',
    password: '',
    actual: '',
    login_email: '',
    tipo_id: null,
    conversiones: true,
    activo: true,
    fact_nombre: '',
    fact_nif: '',
    fact_dir: '',
    fact_email: '',
    est_nombre: '',
    est_etiqueta: '',
    est_siguiente: '',
    fases: FASES_POR_DEFECTO.map((f) => filaFase(f)),
    plan_resumen: '',
    items: [],
    detalle: [],
    accesos: [],
    progreso: [],
  }
}

/* {Junio: {completado, pendiente}} → filas sueltas «mes · estado · título». */
export function progresoAFilas(p: ProgresoMes[]): FilaProgreso[] {
  const filas: FilaProgreso[] = []
  for (const m of p) {
    for (const t of m.completado) filas.push({ key: nuevaKey(), mes: m.mes, estado: 'completado', t: t.t, d: t.d })
    for (const t of m.pendiente) filas.push({ key: nuevaKey(), mes: m.mes, estado: 'pendiente', t: t.t, d: t.d })
  }
  return filas
}

/* Filas → por mes, en el orden en que aparece cada mes. Las filas sin título no cuentan. */
export function filasAProgreso(filas: FilaProgreso[]): ProgresoMes[] {
  const porMes = new Map<string, ProgresoMes>()
  for (const f of filas) {
    if (!f.t.trim()) continue
    const mes = f.mes.trim()
    let m = porMes.get(mes)
    if (!m) {
      m = { mes, completado: [], pendiente: [] }
      porMes.set(mes, m)
    }
    m[f.estado].push({ t: f.t.trim(), d: f.d.trim() })
  }
  return [...porMes.values()]
}

export function formDesdeDatos(d: DatosCliente): FormCliente {
  return {
    name: d.name,
    saludo: d.saludo,
    iniciales: d.iniciales,
    username: d.username,
    password: '',
    actual: d.actual,
    login_email: d.login_email,
    tipo_id: d.tipo_id,
    conversiones: d.conversiones,
    activo: d.activo,
    fact_nombre: d.fact_nombre,
    fact_nif: d.fact_nif,
    fact_dir: d.fact_dir,
    fact_email: d.fact_email,
    est_nombre: d.estado.nombre,
    est_etiqueta: d.estado.etiqueta,
    est_siguiente: d.estado.siguiente,
    fases: d.estado.fases.map((f) => filaFase(f)),
    plan_resumen: d.plan.resumen,
    items: d.plan.items.map((i) => ({ key: nuevaKey(), ...i })),
    detalle: d.plan.detalle.map((i) => ({ key: nuevaKey(), ...i })),
    accesos: d.accesos.map((a) => ({ key: nuevaKey(), ...a })),
    progreso: progresoAFilas(d.tareas),
  }
}

function sinKey<T extends { key: string }>(o: T): Omit<T, 'key'> {
  const copia: Partial<T> = { ...o }
  delete copia.key
  return copia as Omit<T, 'key'>
}

/* Cuerpo para POST/PATCH /clientes. La contraseña solo va si se ha escrito. */
export function cuerpoDesdeForm(f: FormCliente): Record<string, unknown> {
  const estado: EstadoProyecto = {
    nombre: f.est_nombre,
    etiqueta: f.est_etiqueta,
    siguiente: f.est_siguiente,
    fases: f.fases.map(sinKey).filter((x) => x.t.trim() !== ''),
  }
  const cuerpo: Record<string, unknown> = {
    name: f.name,
    saludo: f.saludo,
    iniciales: f.iniciales,
    username: f.username,
    actual: f.actual,
    login_email: f.login_email,
    tipo_id: f.tipo_id,
    conversiones: f.conversiones,
    activo: f.activo,
    fact_nombre: f.fact_nombre,
    fact_nif: f.fact_nif,
    fact_dir: f.fact_dir,
    fact_email: f.fact_email,
    estado,
    plan: {
      resumen: f.plan_resumen,
      items: f.items.map(sinKey).filter((i) => i.n.trim() || i.t.trim()),
      detalle: f.detalle.map(sinKey).filter((i) => i.h.trim() || i.p.trim()),
    },
    accesos: f.accesos.map(sinKey).filter((a) => a.b.trim() !== ''),
    tareas: filasAProgreso(f.progreso),
  }
  if (f.password !== '') cuerpo.password = f.password
  return cuerpo
}

/* ¿Hay algo distinto de lo cargado? Se compara el cuerpo que se mandaría. */
export function hayCambios(actual: FormCliente, inicial: FormCliente) {
  return JSON.stringify(cuerpoDesdeForm(actual)) !== JSON.stringify(cuerpoDesdeForm(inicial))
}

/* Errores que se pueden ver antes de mandar (el servidor los vuelve a mirar). */
export function erroresBasicos(f: FormCliente, esAlta: boolean): Partial<Record<'name' | 'username' | 'password', string>> {
  const e: Partial<Record<'name' | 'username' | 'password', string>> = {}
  if (!f.name.trim()) e.name = 'El nombre es obligatorio.'
  if (!f.username.trim()) e.username = 'El usuario es obligatorio.'
  if (esAlta && f.password === '') e.password = 'Pon una contraseña para el cliente.'
  return e
}

/* Secciones del formulario y qué campo de error lleva a cuál. */
export const SECCIONES_FORM = ['datos', 'facturacion', 'progreso', 'plan', 'accesos', 'progreso-cliente'] as const
export type SeccionForm = (typeof SECCIONES_FORM)[number]
export function seccionDeCampo(campo: string): SeccionForm {
  if (campo.startsWith('fact_')) return 'facturacion'
  if (campo === 'estado') return 'progreso'
  if (campo === 'plan') return 'plan'
  if (campo === 'accesos') return 'accesos'
  if (campo === 'tareas') return 'progreso-cliente'
  return 'datos'
}

/* «#fact» en la URL abre Facturación (enlace «Editar →» de la ficha). */
export function seccionDeHash(hash: string): SeccionForm {
  const h = hash.replace(/^#/, '')
  if (h === 'fact') return 'facturacion'
  return (SECCIONES_FORM as readonly string[]).includes(h) ? (h as SeccionForm) : 'datos'
}
