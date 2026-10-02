import { useState, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import {
  Bell,
  CalendarDays,
  ChevronDown,
  ChevronRight,
  ChevronUp,
  Folder,
  House,
  Inbox,
  List,
  Lock,
  LogOut,
  Plus,
  Settings,
  Users,
  UserCheck,
  Video,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '../../features/auth/useAuth'
import { useNav } from '../../features/nav/api'
import { useCliente, useClientesSeccion } from '../../features/clientes/api'
import type { Cliente } from '../../features/clientes/schemas'
import Avatar from '../../shared/ui/Avatar'

const ITEM =
  'flex items-center gap-[13px] rounded-[11px] px-[13px] py-3 text-[14px] font-medium text-[#5a5f68] transition-[background-color,transform] duration-150 hover:translate-x-0.5 hover:bg-soft dark:text-zinc-400'
const ITEM_ON = 'bg-soft font-semibold !text-ink'

function NavItem({ to, icon: Icon, label, on, extra }: { to: string; icon: LucideIcon; label: string; on: boolean; extra?: ReactNode }) {
  return (
    <Link to={to} className={`${ITEM} ${on ? ITEM_ON : ''} group`} aria-current={on ? 'page' : undefined}>
      <Icon className={`size-[18px] shrink-0 ${on ? 'text-ink' : 'text-label group-hover:text-ink'}`} strokeWidth={1.8} />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      {extra}
    </Link>
  )
}

/* Un cliente de la barra lateral: carpeta plegable con sus listas. */
function ClienteCarpeta({ c, cliActual, listActual }: { c: Cliente; cliActual: number; listActual: number }) {
  const [abierto, setAbierto] = useState(c.id === cliActual)
  const on = c.id === cliActual
  const listas = c.listas ?? []
  return (
    <li>
      <div className={`flex items-center gap-1 rounded-[9px] pr-2 ${on ? 'text-ink' : 'text-[#5c616b] dark:text-zinc-400'}`}>
        <button
          type="button"
          onClick={() => setAbierto((v) => !v)}
          className="flex size-6 shrink-0 items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink"
          aria-label={abierto ? `Plegar ${c.name}` : `Desplegar ${c.name}`}
          aria-expanded={abierto}
        >
          <ChevronRight className={`size-3.5 transition-transform ${abierto ? 'rotate-90' : ''}`} />
        </button>
        <Link
          to={`/tareas?view=cliente&cli=${c.id}`}
          className={`flex min-w-0 flex-1 items-center gap-2 rounded-[9px] px-1.5 py-[7px] text-[13px] hover:bg-soft hover:text-ink ${on ? 'font-semibold' : 'font-medium'}`}
        >
          <Folder className="size-[15px] shrink-0 text-label" strokeWidth={1.8} />
          <span className="truncate">{c.name}</span>
        </Link>
      </div>
      {abierto && (
        <ul className="mt-0.5 mb-1 ml-[30px] flex flex-col gap-0.5">
          {listas.length === 0 && <li className="px-2 py-1.5 text-[12px] text-muted">Sin listas</li>}
          {listas.map((l) => {
            const lOn = on && l.id === listActual
            const Icono = l.tipo === 'informe' ? Inbox : List
            return (
              <li key={l.id}>
                <Link
                  to={`/tareas?view=cliente&cli=${c.id}&list=${l.id}`}
                  className={`flex items-center gap-2 rounded-[9px] px-2 py-1.5 text-[12.5px] transition-transform hover:translate-x-0.5 hover:bg-soft hover:text-ink ${
                    lOn ? 'bg-soft font-semibold text-ink' : 'text-[#5c616b] dark:text-zinc-400'
                  }`}
                >
                  <Icono className="size-3.5 shrink-0 text-label" strokeWidth={1.8} />
                  <span className="min-w-0 flex-1 truncate">{l.nombre}</span>
                  {l.tipo !== 'informe' && l.pend > 0 && <span className="text-[11px] font-semibold text-muted">{l.pend}</span>}
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </li>
  )
}

const AVISO = 'px-2 py-1.5 text-[12px] text-muted'

/* Clientes activos o no activos. Con cientos de clientes no se cargan todos:
   la sección pide sus clientes solo al desplegarse, de 50 en 50 («Ver más»). */
function SeccionClientes({
  titulo,
  punto,
  activo,
  contieneActual,
  derecha,
  cliActual,
  listActual,
}: {
  titulo: string
  punto: string
  activo: boolean
  contieneActual: boolean
  derecha?: ReactNode
  cliActual: number
  listActual: number
}) {
  // Empieza plegada salvo que contenga el cliente que se está viendo; en cuanto
  // se pliega o despliega a mano, manda lo elegido.
  const [elegida, setElegida] = useState<boolean | null>(null)
  const abierta = elegida ?? contieneActual
  const consulta = useClientesSeccion(activo, abierta)
  const clientes = consulta.data?.pages.flatMap((p) => p.items) ?? []
  return (
    <section className="px-2.5">
      <div className="flex items-center gap-2 px-2.5 pt-[22px] pb-[9px]">
        <button
          type="button"
          onClick={() => setElegida(!abierta)}
          className="flex min-w-0 flex-1 items-center gap-2 text-left text-[10.5px] font-bold tracking-[.7px] text-label uppercase hover:text-ink"
          aria-expanded={abierta}
        >
          {abierta ? <ChevronDown className="size-3.5 shrink-0" /> : <ChevronUp className="size-3.5 shrink-0" />}
          <span className="size-[7px] shrink-0 rounded-full" style={{ backgroundColor: punto }} />
          <span className="truncate">{titulo}</span>
        </button>
        {derecha}
      </div>
      {abierta && (
        <ul className="flex flex-col gap-0.5">
          {consulta.isPending && <li className={AVISO}>Cargando…</li>}
          {consulta.error && <li className={AVISO}>{consulta.error.message}</li>}
          {clientes.map((c) => (
            <ClienteCarpeta key={c.id} c={c} cliActual={cliActual} listActual={listActual} />
          ))}
          {consulta.hasNextPage && (
            <li>
              <button
                type="button"
                onClick={() => void consulta.fetchNextPage()}
                disabled={consulta.isFetchingNextPage}
                className="flex w-full items-center rounded-[9px] px-2 py-1.5 text-left text-[12.5px] font-medium text-muted transition-colors hover:bg-soft hover:text-ink disabled:cursor-default"
              >
                {consulta.isFetchingNextPage ? 'Cargando…' : 'Ver más'}
              </button>
            </li>
          )}
        </ul>
      )}
    </section>
  )
}

export default function Sidebar() {
  const { me, logout, can } = useAuth()
  const { data: nav } = useNav()
  const { pathname } = useLocation()
  const [params] = useSearchParams()
  const navigate = useNavigate()

  const enTareas = pathname.startsWith('/tareas')
  const view = params.get('view') || 'all'
  const cliActual = enTareas && view === 'cliente' ? Number(params.get('cli') || 0) : 0
  const listActual = Number(params.get('list') || 0)

  // Solo hace falta saber en qué sección está el cliente que se ve, no la lista entera.
  const { data: actual } = useCliente(cliActual)
  const activoActual = actual ? actual.cliente.activo : null

  async function salir() {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <aside className="sticky top-0 flex h-screen w-[240px] shrink-0 flex-col overflow-y-auto border-r border-line bg-page">
      <div className="px-5 pt-[18px] pb-1.5 text-[15.5px] font-[650] tracking-[-.2px] text-ink-strong">
        {nav?.marca ?? 'Croilab'} ERP
      </div>

      <nav className="flex flex-col gap-2 px-2.5 py-1" aria-label="Tareas">
        <NavItem to="/inicio" icon={House} label="Dashboard" on={pathname === '/inicio'} />
        <NavItem
          to="/notificaciones"
          icon={Bell}
          label="Notificaciones"
          on={pathname === '/notificaciones'}
          extra={
            nav && nav.no_leidas > 0 ? (
              <span className="rounded-full bg-[#feecec] px-2 text-[11px] font-bold text-[#e5484d]">{nav.no_leidas > 9 ? '9+' : nav.no_leidas}</span>
            ) : null
          }
        />
        <NavItem
          to="/tareas?view=all"
          icon={Inbox}
          label="Todas las tareas"
          on={enTareas && view === 'all'}
          extra={nav ? <span className="text-[11px] font-bold text-label">{nav.pendientes}</span> : null}
        />
        <NavItem to="/tareas?view=mine" icon={UserCheck} label="Mis tareas" on={enTareas && view === 'mine'} />
        {can('ver.clientes') && (
          <NavItem
            to="/clientes"
            icon={Users}
            label="Clientes"
            on={pathname.startsWith('/clientes')}
            extra={nav ? <span className="rounded-full bg-soft px-2 text-[11px] font-bold text-label">{nav.clientes.total}</span> : null}
          />
        )}
        {can('ver.agenda') && <NavItem to="/reuniones" icon={Video} label="Reuniones" on={pathname.startsWith('/reuniones')} />}
        <NavItem to="/credenciales" icon={Lock} label="Credenciales" on={pathname.startsWith('/credenciales')} />
        {can('ver.agenda') && <NavItem to="/calendario" icon={CalendarDays} label="Calendario" on={pathname.startsWith('/calendario')} />}
      </nav>

      {nav && (
        <>
          <SeccionClientes
            titulo="Clientes activos"
            punto="#12a150"
            activo
            contieneActual={activoActual === true}
            cliActual={cliActual}
            listActual={listActual}
            derecha={
              can('clientes.crear') ? (
                <Link to="/clientes/nuevo" className="flex size-5 items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink" aria-label="Añadir cliente" title="Añadir cliente">
                  <Plus className="size-3.5" strokeWidth={2.2} />
                </Link>
              ) : null
            }
          />
          {nav.clientes.inactivos > 0 && (
            <SeccionClientes
              titulo="Clientes no activos"
              punto="#f59e0b"
              activo={false}
              contieneActual={activoActual === false}
              cliActual={cliActual}
              listActual={listActual}
              derecha={<span className="text-[11px] font-bold text-label">{nav.clientes.inactivos}</span>}
            />
          )}
        </>
      )}

      <div className="sticky bottom-0 z-[2] mt-auto border-t border-line bg-page px-3 py-2.5">
        <div className="flex items-center gap-0.5">
          <Link to="/perfil" className="flex min-w-0 flex-1 items-center gap-2.5 rounded-[10px] px-2 py-[7px] hover:bg-soft">
            <Avatar nombre={me?.username ?? '?'} foto={me?.foto} size={32} />
            <span className="min-w-0 flex-1">
              <b className="block truncate text-[13px] text-ink-strong">{me?.username}</b>
              <span className="block truncate text-[11px] text-muted">{me?.role_nombre || me?.role}</span>
            </span>
          </Link>
          <Link
            to={can('ver.ajustes') ? '/ajustes' : '/perfil'}
            className="flex shrink-0 rounded-[9px] p-2 text-label transition-colors hover:bg-soft hover:text-ink"
            aria-label={can('ver.ajustes') ? 'Gestión y ajustes' : 'Mi cuenta'}
            title={can('ver.ajustes') ? 'Gestión y ajustes' : 'Mi cuenta'}
          >
            <Settings className="size-[18px]" strokeWidth={1.8} />
          </Link>
        </div>
        <button
          type="button"
          onClick={salir}
          className="mt-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-[12.5px] text-muted transition-colors hover:text-ink"
        >
          <LogOut className="size-4" strokeWidth={1.8} /> Cerrar sesión
        </button>
      </div>
    </aside>
  )
}
