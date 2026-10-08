import { Link, useLocation, useNavigate } from 'react-router-dom'
import { LogOut, Settings } from 'lucide-react'
import { useAuth } from '../../features/auth/useAuth'
import { useNav } from '../../features/nav/api'
import Avatar from '../../shared/ui/Avatar'
import { moduloDe, tituloModulo } from '../navegacion'
import NavSecciones from './NavSecciones'

/* Barra lateral del módulo en el que se está (registro en app/navegacion.tsx):
   título, secciones, contenido propio del módulo y el pie con la cuenta. */
export default function Sidebar() {
  const { me, logout, can } = useAuth()
  const { data: nav } = useNav()
  const { pathname } = useLocation()
  const navigate = useNavigate()
  const modulo = moduloDe(pathname)
  const titulo = tituloModulo(modulo, nav)

  async function salir() {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <aside className="sticky top-0 flex h-screen w-[240px] shrink-0 flex-col overflow-y-auto border-r border-line bg-page" aria-label={titulo}>
      <div className="px-5 pt-[18px] pb-1.5 text-[15.5px] font-[650] tracking-[-.2px] text-ink-strong">{titulo}</div>

      <NavSecciones sections={modulo.sections} nav={nav} ariaLabel={modulo.id === 'trabajo' ? 'Tareas' : titulo} />

      {modulo.extra}

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
