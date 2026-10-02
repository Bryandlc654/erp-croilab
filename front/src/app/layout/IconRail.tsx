import { NavLink } from 'react-router-dom'
import {
  BriefcaseBusiness,
  CalendarDays,
  Euro,
  House,
  MessageCircle,
  PenLine,
  Sparkles,
  SquareCheckBig,
  Ticket,
  Users,
  Video,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '../../features/auth/useAuth'

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

export default function IconRail({ marca }: { marca: string }) {
  const { can } = useAuth()
  return (
    <nav
      aria-label="Módulos"
      className="sticky top-2 z-20 my-2 mr-1.5 ml-2 flex h-[calc(100vh-16px)] w-[66px] shrink-0 flex-col items-center gap-[9px] overflow-x-hidden overflow-y-auto rounded-[20px] bg-[#0f1012] py-3 [scrollbar-width:none]"
    >
      <NavLink
        to="/inicio"
        className="mb-3 flex size-[34px] shrink-0 items-center justify-center rounded-[10px] bg-white text-[15px] font-extrabold text-[#0f1012]"
        aria-label={marca}
        title={marca}
      >
        {marca.charAt(0).toLowerCase()}
      </NavLink>
      {RAIL.filter((i) => i.permiso === null || can(i.permiso)).map(({ to, label, icon: Icon }) => (
        <div key={to} className="relative flex w-full shrink-0 justify-center">
          <NavLink
            to={to}
            className={({ isActive }) =>
              `relative flex size-[50px] flex-col items-center justify-center gap-[3px] rounded-[15px] transition-[background-color,color,transform] duration-150 hover:-translate-y-px ${
                isActive
                  ? "bg-[#2a2c31] text-white before:absolute before:top-3 before:bottom-3 before:-left-[9px] before:w-[3px] before:rounded-r-[3px] before:bg-white before:content-['']"
                  : 'text-[#7e838d] hover:bg-[#1e2024] hover:text-white'
              }`
            }
          >
            <Icon className="size-[18px]" strokeWidth={1.8} />
            <span className="w-full text-center text-[9px] leading-[1.1] font-semibold tracking-[.1px]">{label}</span>
          </NavLink>
        </div>
      ))}
    </nav>
  )
}
