/* Lógica pura del módulo (sin React), con sus tests en logica.test.ts. */

export type Requisitos = Record<string, string[]>

/* Lo que hace falta activar además de `perm` para que sirva de algo (y lo que
   eso a su vez necesita), quitando lo que el rol ya tiene. Es el aviso «Hacen
   falta otros permisos» de la matriz; el servidor lo completa igualmente. */
export function requisitosQueFaltan(perm: string, tiene: string[], req: Requisitos): string[] {
  const out: string[] = []
  const pendientes = [perm]
  while (pendientes.length) {
    const p = pendientes.pop() as string
    for (const r of req[p] ?? []) {
      if (r !== perm && !tiene.includes(r) && !out.includes(r)) {
        out.push(r)
        pendientes.push(r)
      }
    }
  }
  return out
}

/* Lo que se apaga si se quita `perm`: los permisos activos que lo necesitan,
   directa o indirectamente. Es el aviso «Se apagarán otros permisos». */
export function dependientesQueCaen(perm: string, tiene: string[], req: Requisitos): string[] {
  const out: string[] = []
  const pendientes = [perm]
  while (pendientes.length) {
    const p = pendientes.pop() as string
    for (const [k, necesita] of Object.entries(req)) {
      if (necesita.includes(p) && tiene.includes(k) && k !== perm && !out.includes(k)) {
        out.push(k)
        pendientes.push(k)
      }
    }
  }
  return out
}

/* «caduca en ~N h» (o en minutos si queda menos de una hora). */
export function caducaEn(iso: string, ahora: Date = new Date()): string {
  const ms = new Date(iso).getTime() - ahora.getTime()
  if (!Number.isFinite(ms) || ms <= 0) return 'caducado'
  const min = Math.round(ms / 60000)
  if (min < 60) return `caduca en ${Math.max(1, min)} min`
  return `caduca en ~${Math.round(min / 60)} h`
}

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

/* «4 de mayo» (largo) o «4 may» (corto) a partir de YYYY-MM-DD. */
export function fechaCumple(iso: string | null | undefined, corto = false): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '')
  if (!m) return ''
  const mes = MESES[Number(m[2]) - 1]
  if (!mes) return ''
  return corto ? `${Number(m[3])} ${mes.slice(0, 3)}` : `${Number(m[3])} de ${mes}`
}

/* «08/10/2026 a las 09:30» */
export function fechaHora(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const dos = (n: number) => String(n).padStart(2, '0')
  return `${dos(d.getDate())}/${dos(d.getMonth() + 1)}/${d.getFullYear()} a las ${dos(d.getHours())}:${dos(d.getMinutes())}`
}

/* dd/mm/aaaa de un YYYY-MM-DD. */
export function fechaCorta(iso: string | null | undefined): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso ?? '')
  return m ? `${m[3]}/${m[2]}/${m[1]}` : ''
}

export function quitarTildes(s: string) {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}

/* Índice del buscador de ajustes («Buscar un ajuste…»): palabras → apartado. */
export type EntradaAjuste = { palabras: string; titulo: string; donde: string; to: string }

export const INDICE_AJUSTES: EntradaAjuste[] = [
  { palabras: 'nombre agencia empresa marca', titulo: 'Nombre de la agencia', donde: 'Agencia', to: '/ajustes' },
  { palabras: 'cif nif fiscal', titulo: 'CIF / NIF', donde: 'Agencia', to: '/ajustes' },
  { palabras: 'email correo telefono web direccion localizan', titulo: 'Cómo te localizan', donde: 'Agencia', to: '/ajustes' },
  { palabras: 'logo imagen color marca portal', titulo: 'Tu marca (logo y color)', donde: 'Agencia', to: '/ajustes' },
  { palabras: 'equipo miembro usuario alta baja invitar persona', titulo: 'Mi equipo', donde: 'Organización', to: '/ajustes/equipo' },
  { palabras: 'registro enlace invitacion', titulo: 'Registro por enlace', donde: 'Mi equipo', to: '/ajustes/equipo' },
  { palabras: 'contrasena password clave', titulo: 'Contraseña de un miembro', donde: 'Mi equipo', to: '/ajustes/equipo' },
  { palabras: 'autonomo tarifa hora iva irpf', titulo: 'Si factura sus horas', donde: 'Mi equipo', to: '/ajustes/equipo' },
  { palabras: 'rol roles permisos acceso dueno editor lectura', titulo: 'Roles y permisos', donde: 'Organización', to: '/ajustes/roles' },
  { palabras: 'facturacion emisor serie iban banco', titulo: 'Facturación', donde: 'Organización', to: '/ajustes/facturacion' },
  { palabras: 'boveda credenciales contrasenas accesos hosting', titulo: 'Bóveda de credenciales', donde: 'Clientes', to: '/ajustes/boveda' },
  { palabras: 'whatsapp contacto portal reservas cita calendario', titulo: 'Contacto del portal', donde: 'Portal de clientes', to: '/ajustes/portal/contacto' },
  { palabras: 'video youtube presentacion servicio', titulo: 'Vídeos del portal', donde: 'Portal de clientes', to: '/ajustes/portal/videos' },
  { palabras: 'metricas google search console analytics ga4 conversiones eventos', titulo: 'Métricas de Google', donde: 'Portal de clientes', to: '/ajustes/portal/metricas' },
  { palabras: 'reglas automaticas cron avisos recurrentes papelera resumen', titulo: 'Reglas automáticas', donde: 'Sistema', to: '/ajustes/reglas' },
  { palabras: 'integraciones n8n api token', titulo: 'n8n / API', donde: 'Integraciones', to: '/ajustes/integraciones?i=api' },
  { palabras: 'google calendar calendario oauth client id secreto', titulo: 'Google Calendar', donde: 'Integraciones', to: '/ajustes/integraciones?i=calendar' },
  { palabras: 'google metricas oauth search console analytics', titulo: 'Google · Métricas', donde: 'Integraciones', to: '/ajustes/integraciones?i=metricas' },
  { palabras: 'mcp claude conector ia', titulo: 'MCP · Claude', donde: 'Integraciones', to: '/ajustes/integraciones?i=mcp' },
  { palabras: 'papelera borrado restaurar', titulo: 'Papelera', donde: 'Sistema', to: '/ajustes/papelera' },
  { palabras: 'mi cuenta perfil foto cumpleanos', titulo: 'Mi cuenta', donde: 'Perfil', to: '/perfil?modo=cuenta' },
]

/* Sin tildes ni mayúsculas, desde 2 letras, máximo 10 resultados (como el antiguo). */
export function buscarAjustes(q: string, indice: EntradaAjuste[] = INDICE_AJUSTES): EntradaAjuste[] {
  const t = quitarTildes(q.trim())
  if (t.length < 2) return []
  const partes = t.split(/\s+/)
  return indice.filter((e) => partes.every((p) => quitarTildes(`${e.palabras} ${e.titulo} ${e.donde}`).includes(p))).slice(0, 10)
}

/* Solo cifras, como lo guarda la API (wa.me/<número>). */
export function soloCifras(s: string) {
  return s.replace(/\D+/g, '')
}

/* #1f232a válido (3 o 6 cifras hexadecimales), normalizado. null si no vale. */
export function colorValido(s: string): string | null {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(s.trim())
  if (!m) return null
  const h = m[1].toLowerCase()
  return '#' + (h.length === 3 ? h.split('').map((c) => c + c).join('') : h)
}

/* Formularios: ¿ha cambiado algo respecto a lo guardado? */
export function hayCambios<T extends Record<string, unknown>>(actual: T, guardado: T): boolean {
  return Object.keys(actual).some((k) => JSON.stringify(actual[k]) !== JSON.stringify(guardado[k]))
}

/* Iniciales de la agencia para las maquetas de marca. */
export function inicialMarca(nombre: string) {
  return (nombre.trim()[0] ?? 'C').toUpperCase()
}
