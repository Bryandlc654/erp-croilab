import type { ReactNode } from 'react'
import {
  Bell,
  Building2,
  Calculator,
  CalendarClock,
  CalendarDays,
  ChartLine,
  Clock,
  Euro,
  Eye,
  FileText,
  Flag,
  House,
  Inbox,
  Layers,
  LayoutGrid,
  List,
  Lock,
  MessageCircle,
  PenLine,
  Plug,
  Sparkles,
  Ticket,
  Trash2,
  UserCheck,
  UserRound,
  Users,
  Video,
  Zap,
  type LucideIcon,
} from 'lucide-react'
import type { Nav } from '../features/nav/schemas'
import SidebarClientes from './layout/SidebarClientes'
import ListasCrmDiferidas from './layout/ListasCrmDiferidas'

/* Registro de la barra lateral por módulo. Cada módulo declara cuándo es suyo
   el path actual (`match`), su título y sus secciones; la barra lateral pinta
   el primero que encaja (o el de Trabajo si ninguno). Para añadir un módulo:
   una entrada aquí y sus rutas en App.tsx (docs/migracion/RUTAS.md). */

export type Ubicacion = { pathname: string; params: URLSearchParams }

export type ItemNav = {
  to: string
  label: string
  icon: LucideIcon
  /* Permiso necesario para verlo (si no, no se pinta). */
  perm?: string
  /* Contador a la derecha; recibe los contadores del menú (/api/v1/nav). */
  count?: (nav: Nav | undefined) => ReactNode
  /* Cuándo está activo. Por defecto: mismo path y los mismos parámetros que `to`. */
  activo?: (u: Ubicacion) => boolean
}

export type SeccionNav = {
  label?: string
  items: ItemNav[]
  /* Texto si la sección no tiene elementos («Sin listas todavía.»). */
  vacio?: string
  /* Botón «+» de la cabecera. */
  accion?: { label: string; to: string; perm?: string }
}

export type ModuloNav = {
  id: string
  match: (pathname: string) => boolean
  title: string | ((nav: Nav | undefined) => string)
  sections: SeccionNav[]
  /* Contenido propio debajo de las secciones (p. ej. los clientes con sus listas). */
  extra?: ReactNode
  /* Pantallas «solo» (Reuniones, Calendario, Chat…): en escritorio sin barra lateral. */
  solo?: boolean
}

const bajo = (base: string) => (p: string) => p === base || p.startsWith(base + '/')
const empiezaPor = (base: string) => (u: Ubicacion) => bajo(base)(u.pathname)

/* ¿Está activo el elemento en esta ubicación? */
export function itemActivo(item: ItemNav, u: Ubicacion) {
  if (item.activo) return item.activo(u)
  const [path, query = ''] = item.to.split('?')
  if (u.pathname !== path) return false
  for (const [k, v] of new URLSearchParams(query)) if (u.params.get(k) !== v) return false
  return true
}

// Contadores con el mismo aspecto que tenían en la barra de Tareas.
const contadorRojo = (n: number) =>
  n > 0 ? <span className="rounded-full bg-[#feecec] px-2 text-[11px] font-bold text-[#e5484d]">{n > 9 ? '9+' : n}</span> : null
const contadorPlano = (n: number | undefined) => (n === undefined ? null : <span className="text-[11px] font-bold text-label">{n}</span>)
const contadorSuave = (n: number | undefined) => (n === undefined ? null : <span className="rounded-full bg-soft px-2 text-[11px] font-bold text-label">{n}</span>)

const vistaTareas = (u: Ubicacion) => (u.pathname.startsWith('/tareas') ? u.params.get('view') || 'all' : null)
const filtroClientes = (u: Ubicacion) => (u.pathname === '/clientes' ? u.params.get('f') || 'alta' : null)
/* Ficha, edición y métricas de un cliente (/clientes/12…): cuentan como «Clientes en alta». */
const fichaCliente = (u: Ubicacion) => /^\/clientes\/(\d+|nuevo)(\/|$)/.test(u.pathname)

/* Trabajo: Inicio, Tareas, avisos… La barra de siempre del tablero de tareas. */
const TRABAJO: ModuloNav = {
  id: 'trabajo',
  match: (p) => ['/inicio', '/tareas', '/notificaciones', '/buscar', '/credenciales', '/perfil'].some((b) => bajo(b)(p)),
  title: (nav) => `${nav?.marca ?? 'Croilab'} ERP`,
  sections: [
    {
      items: [
        { to: '/inicio', label: 'Dashboard', icon: House },
        { to: '/notificaciones', label: 'Notificaciones', icon: Bell, count: (nav) => (nav ? contadorRojo(nav.no_leidas) : null) },
        { to: '/tareas?view=all', label: 'Todas las tareas', icon: Inbox, count: (nav) => contadorPlano(nav?.pendientes), activo: (u) => vistaTareas(u) === 'all' },
        { to: '/tareas?view=mine', label: 'Mis tareas', icon: UserCheck, activo: (u) => vistaTareas(u) === 'mine' },
        { to: '/clientes', label: 'Clientes', icon: Users, perm: 'ver.clientes', count: (nav) => contadorSuave(nav?.clientes.total), activo: empiezaPor('/clientes') },
        { to: '/reuniones', label: 'Reuniones', icon: Video, perm: 'ver.agenda', activo: empiezaPor('/reuniones') },
        { to: '/credenciales', label: 'Credenciales', icon: Lock, activo: empiezaPor('/credenciales') },
        { to: '/calendario', label: 'Calendario', icon: CalendarDays, perm: 'ver.agenda', activo: empiezaPor('/calendario') },
      ],
    },
  ],
  extra: <SidebarClientes />,
}

const CLIENTES: ModuloNav = {
  id: 'clientes',
  match: bajo('/clientes'),
  title: 'Clientes',
  sections: [
    {
      items: [
        { to: '/clientes?f=alta', label: 'Clientes en alta', icon: Users, count: (nav) => contadorPlano(nav?.clientes.activos), activo: (u) => filtroClientes(u) === 'alta' || fichaCliente(u) },
        { to: '/clientes?f=baja', label: 'No activos', icon: UserRound, count: (nav) => contadorPlano(nav?.clientes.inactivos), activo: (u) => filtroClientes(u) === 'baja' },
        { to: '/clientes?f=todos', label: 'Todos', icon: List, count: (nav) => contadorPlano(nav?.clientes.total), activo: (u) => filtroClientes(u) === 'todos' },
      ],
    },
    {
      label: 'Configuración',
      items: [
        { to: '/clientes/tipos', label: 'Tipos de cliente', icon: Flag },
        { to: '/clientes/servicios', label: 'Servicios', icon: Layers },
        { to: '/clientes/agencias', label: 'Agencias', icon: Building2 },
        { to: '/finanzas/precios', label: 'Calculadora de precios', icon: Calculator, perm: 'ver.finanzas' },
      ],
    },
  ],
}

const CRM: ModuloNav = {
  id: 'crm',
  match: bajo('/crm'),
  title: 'CRM · Ventas',
  sections: [
    {
      items: [
        { to: '/crm', label: 'Contactos', icon: Users, activo: (u) => u.pathname === '/crm' || bajo('/crm/contactos')(u.pathname) || bajo('/crm/importar')(u.pathname) },
        { to: '/crm/negocio', label: 'Negocio', icon: LayoutGrid },
        { to: '/crm/dashboard', label: 'Dashboard', icon: ChartLine },
        { to: '/crm/reporting', label: 'Reporting', icon: Zap },
        { to: '/reuniones', label: 'Reuniones', icon: CalendarDays, perm: 'ver.agenda' },
      ],
    },
  ],
  // Listas de contactos (reordenables, con menú): /crm/listas/:id.
  extra: <ListasCrmDiferidas />,
}

const FINANZAS: ModuloNav = {
  id: 'finanzas',
  match: bajo('/finanzas'),
  title: 'Finanzas',
  sections: [
    {
      label: 'General',
      items: [
        { to: '/finanzas', label: 'Resumen mensual', icon: ChartLine },
        { to: '/finanzas/proyectos', label: 'Proyectos', icon: Layers, perm: 'ver.proyectos' },
        { to: '/finanzas/horas', label: 'Horas equipo', icon: Clock, perm: 'ver.horas' },
        { to: '/finanzas/precios', label: 'Calculadora de precios', icon: Calculator, perm: 'ver.finanzas' },
      ],
    },
    {
      label: 'Facturas',
      items: [
        { to: '/finanzas/facturas', label: 'Todas', icon: FileText, perm: 'ver.finanzas', activo: empiezaPor('/finanzas/facturas') },
        { to: '/finanzas/clientes', label: 'Por cliente', icon: Users, perm: 'ver.finanzas' },
        { to: '/finanzas/programaciones', label: 'Programaciones', icon: CalendarClock, perm: 'ver.finanzas' },
      ],
    },
    {
      label: 'Contabilidad',
      items: [
        { to: '/finanzas/contabilidad', label: 'Movimientos', icon: House, perm: 'ver.conta', activo: (u) => u.pathname === '/finanzas/contabilidad' },
        { to: '/finanzas/contabilidad/analisis', label: 'Análisis / gráficas', icon: ChartLine, perm: 'ver.conta' },
      ],
    },
  ],
}

const AJUSTES: ModuloNav = {
  id: 'ajustes',
  match: bajo('/ajustes'),
  title: 'Gestión y ajustes',
  sections: [
    {
      label: 'Organización',
      items: [
        { to: '/ajustes', label: 'Agencia', icon: House },
        { to: '/ajustes/facturacion', label: 'Facturación', icon: Euro, perm: 'ver.finanzas' },
        { to: '/ajustes/equipo', label: 'Mi equipo', icon: UserRound, perm: 'equipo.gestionar', activo: empiezaPor('/ajustes/equipo') },
        { to: '/ajustes/roles', label: 'Roles y permisos', icon: UserCheck, perm: 'roles.gestionar' },
        { to: '/clientes/servicios', label: 'Servicios', icon: List },
      ],
    },
    {
      label: 'Clientes',
      items: [
        { to: '/clientes?f=alta', label: 'Clientes en alta', icon: Users, perm: 'ver.clientes' },
        { to: '/clientes/tipos', label: 'Tipos de cliente', icon: Flag },
        { to: '/ajustes/boveda', label: 'Bóveda de credenciales', icon: Lock, activo: empiezaPor('/ajustes/boveda') },
      ],
    },
    {
      label: 'Portal de clientes',
      items: [
        { to: '/ajustes/portal/contacto', label: 'Contacto', icon: MessageCircle },
        { to: '/ajustes/portal/videos', label: 'Vídeos', icon: Eye },
        { to: '/ajustes/portal/metricas', label: 'Métricas de Google', icon: ChartLine },
      ],
    },
    {
      label: 'Sistema',
      items: [
        { to: '/ajustes/reglas', label: 'Reglas automáticas', icon: Zap, perm: 'ver.ajustes' },
        { to: '/ajustes/integraciones', label: 'Integraciones', icon: Plug, activo: empiezaPor('/ajustes/integraciones') },
        { to: '/ajustes/papelera', label: 'Papelera', icon: Trash2 },
      ],
    },
  ],
}

/* Pantallas a toda anchura del ERP: sin barra lateral en escritorio; en el
   cajón del móvil enseñan su enlace y el de su pareja. */
function solo(id: string, title: string, items: ItemNav[]): ModuloNav {
  return { id, match: bajo(items[0].to), title, sections: [{ items }], solo: true }
}

const REUNION: ItemNav = { to: '/reuniones', label: 'Reuniones', icon: Video, perm: 'ver.agenda', activo: empiezaPor('/reuniones') }
const CALENDARIO: ItemNav = { to: '/calendario', label: 'Calendario', icon: CalendarDays, perm: 'ver.agenda', activo: empiezaPor('/calendario') }

export const modulosNav: ModuloNav[] = [
  CLIENTES,
  CRM,
  FINANZAS,
  AJUSTES,
  solo('reuniones', 'Reuniones', [REUNION, CALENDARIO]),
  solo('calendario', 'Calendario', [CALENDARIO, REUNION]),
  solo('chat', 'Chat', [{ to: '/chat', label: 'Chat', icon: MessageCircle, activo: empiezaPor('/chat') }]),
  solo('soporte', 'Soporte', [{ to: '/soporte', label: 'Tickets', icon: Ticket, activo: empiezaPor('/soporte') }]),
  solo('actas', 'Actas', [{ to: '/actas', label: 'Actas', icon: PenLine, activo: empiezaPor('/actas') }]),
  solo('asistente', 'Asistente', [{ to: '/asistente', label: 'Asistente', icon: Sparkles }]),
  TRABAJO,
]

/* Módulo de un path; Trabajo si ninguno lo reclama. */
export function moduloDe(pathname: string, modulos: ModuloNav[] = modulosNav): ModuloNav {
  return modulos.find((m) => m.match(pathname)) ?? modulos.find((m) => m.id === 'trabajo') ?? TRABAJO
}

export function tituloModulo(m: ModuloNav, nav: Nav | undefined) {
  return typeof m.title === 'function' ? m.title(nav) : m.title
}
