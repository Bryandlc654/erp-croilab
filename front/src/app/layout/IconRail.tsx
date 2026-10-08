import { useState } from 'react'
import { NavLink, useLocation } from 'react-router-dom'
import {
  Bell,
  BriefcaseBusiness,
  CalendarDays,
  Euro,
  House,
  MessageCircle,
  PenLine,
  Settings,
  Sparkles,
  SquareCheckBig,
  Ticket,
  Users,
  Video,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '../../features/auth/useAuth'
import { useNav } from '../../features/nav/api'
import type { Nav } from '../../features/nav/schemas'
import Avatar from '../../shared/ui/Avatar'
import CountBadge from '../../shared/ui/CountBadge'
import RailFlyout from '../../shared/ui/RailFlyout'
import { useIntencion } from '../../shared/lib/useIntencion'
import { moduloDe, tituloModulo } from '../navegacion'
import NavSecciones from './NavSecciones'

type Item = { to: string; label: string; icon: LucideIcon; permiso: string | null }

/* Mismo orden y permisos que el raíl del ERP antiguo ($railTodo en erp_nav.php). */
const RAIL: Item[] = [
  { to: '/inicio', label: 'Inicio', icon: House, permiso: null },
  { to: '/tareas', label: 'Tareas', icon: SquareCheckBig, permiso: 'ver.tareas' },
  { to: '/clientes', label: 'Clientes', icon: Users, permiso: 'ver.clientes' },
  { to: '/crm', label: 'CRM', icon: BriefcaseBusiness, permiso: 'ver.crm' },
  { to: '/finanzas', label: 'Finanzas', icon: Euro, permiso: 'ver.finanzas' },
  { to: '/reuniones', label: 'Reuniones', icon: Video, permiso: 'ver.agenda' },
  { to: '/calendario', label: 'Calendario', icon: CalendarDays, permiso: 'ver.agenda' },
  { to: '/chat', label: 'Chat', icon: MessageCircle, permiso: 'ver.chat' },
  { to: '/asistente', label: 'Asistente', icon: Sparkles, permiso: 'ver.ia' },
  { to: '/soporte', label: 'Soporte', icon: Ticket, permiso: 'ver.soporte' },
  { to: '/actas', label: 'Actas', icon: PenLine, permiso: 'ver.actas' },
]

const ACTIVO = "bg-[#2a2c31] text-white before:absolute before:top-3 before:bottom-3 before:-left-[9px] before:w-[3px] before:rounded-r-[3px] before:bg-white before:content-['']"
const REPOSO = 'text-[#7e838d] hover:bg-[#1e2024] hover:text-white'

/* Raíl negro de módulos. Al dejar el ratón quieto sobre un módulo se abre su
   menú al lado (como .rail-fly); abajo, ajustes, campana de avisos y cuenta.
   `flyouts` se apaga en el cajón del móvil (allí no hay ratón). */
/* Marca de la agencia arriba del raíl: su logo si lo tiene; si no, la
   inicial sobre su color (o el cuadrado blanco de siempre). */
function MarcaRail({ marca, info }: { marca: string; info?: Nav['marca_info'] }) {
  const [roto, setRoto] = useState('')
  const logo = info?.logo ?? ''
  if (logo && roto !== logo) {
    return <img src={logo} alt="" onError={() => setRoto(logo)} className="size-full rounded-[10px] bg-white object-contain p-0.5" />
  }
  const color = info?.color ?? ''
  return (
    <span className={`flex size-full items-center justify-center rounded-[10px] ${color ? 'text-white' : 'bg-white text-[#0f1012]'}`} style={color ? { backgroundColor: color } : undefined}>
      {marca.charAt(0).toLowerCase()}
    </span>
  )
}

export default function IconRail({ marca, marcaInfo, flyouts = true }: { marca: string; marcaInfo?: Nav['marca_info']; flyouts?: boolean }) {
  const { can, me } = useAuth()
  // El globo lo mantiene al día el Avisador (sondeo de avisos cada 5 s).
  const { data: nav } = useNav()
  const { pathname } = useLocation()
  const fly = useIntencion<string>({ abrirMs: 380, cerrarMs: 200 })
  const actual = moduloDe(pathname)

  // El globo de la campana late cuando sube el número de avisos sin leer.
  const noLeidas = nav?.no_leidas ?? 0
  const [visto, setVisto] = useState(noLeidas)
  const [latidos, setLatidos] = useState(0)
  if (noLeidas !== visto) {
    if (noLeidas > visto) setLatidos((n) => n + 1)
    setVisto(noLeidas)
  }

  const flyModulo = fly.abierto ? moduloDe(fly.abierto) : null
  const ajustes = can('ver.ajustes')

  return (
    <nav
      aria-label="Módulos"
      className="sticky top-2 z-20 my-2 mr-1.5 ml-2 flex h-[calc(100vh-16px)] w-[66px] shrink-0 flex-col items-center gap-[9px] overflow-x-hidden overflow-y-auto rounded-[20px] bg-[#0f1012] py-3 [scrollbar-width:none]"
    >
      <NavLink
        to="/inicio"
        className="mb-3 flex size-[34px] shrink-0 items-center justify-center rounded-[10px] text-[15px] font-extrabold"
        aria-label={marca}
        title={marca}
      >
        <MarcaRail marca={marca} info={marcaInfo} />
      </NavLink>
      {RAIL.filter((i) => i.permiso === null || can(i.permiso)).map(({ to, label, icon: Icon }) => {
        const m = moduloDe(to)
        // Desplegable solo para módulos con menú propio y que no son el de la barra lateral visible.
        const conFly = flyouts && !m.solo && m.id !== actual.id
        return (
          <div
            key={to}
            className="relative flex w-full shrink-0 justify-center"
            onMouseEnter={conFly ? () => fly.entrar(to) : undefined}
            onMouseLeave={conFly ? fly.salir : undefined}
          >
            <NavLink
              to={to}
              onClick={fly.cerrar}
              className={({ isActive }) =>
                `relative flex size-[50px] flex-col items-center justify-center gap-[3px] rounded-[15px] transition-[background-color,color,transform] duration-150 hover:-translate-y-px ${isActive ? ACTIVO : REPOSO}`
              }
            >
              <Icon className="size-[18px]" strokeWidth={1.8} />
              <span className="w-full text-center text-[9px] leading-[1.1] font-semibold tracking-[.1px]">{label}</span>
              {to === '/chat' && (nav?.chat_no_leidos ?? 0) > 0 && (
                <span className="absolute top-[3px] right-[3px]" aria-label={`${nav?.chat_no_leidos} mensajes sin leer`}>
                  <CountBadge n={nav?.chat_no_leidos ?? 0} max={9} tone="rail" />
                </span>
              )}
            </NavLink>
          </div>
        )
      })}

      <div className="mt-auto flex shrink-0 flex-col items-center gap-[9px] pt-3">
        <div className="relative flex w-full justify-center">
          <NavLink
            to={ajustes ? '/ajustes' : '/perfil'}
            end={!ajustes}
            aria-label={ajustes ? 'Gestión y ajustes' : 'Mi cuenta'}
            title={ajustes ? 'Gestión y ajustes' : 'Mi cuenta'}
            className={({ isActive }) =>
              `relative flex size-11 items-center justify-center rounded-[13px] transition-[background-color,color,transform] duration-150 hover:-translate-y-px ${isActive ? ACTIVO : REPOSO}`
            }
          >
            <Settings className="size-5" strokeWidth={1.8} />
          </NavLink>
        </div>
        <div className="relative flex w-full justify-center">
          <NavLink
            to="/notificaciones"
            aria-label={noLeidas > 0 ? `Notificaciones: ${noLeidas} sin leer` : 'Notificaciones'}
            title="Notificaciones"
            className={({ isActive }) =>
              `relative flex size-11 items-center justify-center rounded-[13px] transition-[background-color,color,transform] duration-150 hover:-translate-y-px ${isActive ? ACTIVO : REPOSO}`
            }
          >
            <Bell className="size-5" strokeWidth={1.8} />
            {noLeidas > 0 && (
              <span key={latidos} className={`absolute top-[5px] right-[5px] ${latidos > 0 ? 'motion-safe:animate-bell' : ''}`} aria-hidden="true">
                <CountBadge n={noLeidas} max={9} tone="rail" />
              </span>
            )}
          </NavLink>
        </div>
        {/* En pantallas bajas no cabe: la cuenta sigue en el pie de la barra lateral. */}
        <NavLink to="/perfil" end aria-label="Mi cuenta" title={me?.username ?? 'Mi cuenta'} className="mt-1 flex rounded-full transition-transform hover:-translate-y-px [@media(max-height:900px)]:hidden">
          <Avatar nombre={me?.username ?? '?'} foto={me?.foto} size={32} />
        </NavLink>
      </div>

      {flyouts && flyModulo && (
        <RailFlyout open title={tituloModulo(flyModulo, nav)} onClose={fly.cerrar} onMouseEnter={fly.mantener} onMouseLeave={fly.salir}>
          <NavSecciones sections={flyModulo.sections} nav={nav} ariaLabel={tituloModulo(flyModulo, nav)} />
        </RailFlyout>
      )}
    </nav>
  )
}
