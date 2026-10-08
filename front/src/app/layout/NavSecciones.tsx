import type { ReactNode } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { Plus, type LucideIcon } from 'lucide-react'
import { useAuth } from '../../features/auth/useAuth'
import type { Nav } from '../../features/nav/schemas'
import { itemActivo, type SeccionNav } from '../navegacion'

const ITEM =
  'flex items-center gap-[13px] rounded-[11px] px-[13px] py-3 text-[14px] font-medium text-[#5a5f68] transition-[background-color,transform] duration-150 hover:translate-x-0.5 hover:bg-soft dark:text-zinc-400'
const ITEM_ON = 'bg-soft font-semibold !text-ink'

export function NavItem({ to, icon: Icon, label, on, extra }: { to: string; icon: LucideIcon; label: string; on: boolean; extra?: ReactNode }) {
  return (
    <Link to={to} className={`${ITEM} ${on ? ITEM_ON : ''} group`} aria-current={on ? 'page' : undefined}>
      <Icon className={`size-[18px] shrink-0 ${on ? 'text-ink' : 'text-label group-hover:text-ink'}`} strokeWidth={1.8} />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      {extra}
    </Link>
  )
}

/* Las secciones de un módulo (las de la barra lateral y las del desplegable
   del raíl), filtradas por permiso. */
export default function NavSecciones({ sections, nav, ariaLabel }: { sections: SeccionNav[]; nav: Nav | undefined; ariaLabel: string }) {
  const { can } = useAuth()
  const { pathname, search } = useLocation()
  const u = { pathname, params: new URLSearchParams(search) }

  return (
    <>
      {sections.map((s, i) => {
        const items = s.items.filter((it) => !it.perm || can(it.perm))
        // Una sección con título que se queda sin nada que enseñar (por permisos) no se pinta.
        if (s.label && items.length === 0 && !s.vacio) return null
        return (
          <div key={s.label ?? i}>
            {s.label && (
              <div className="flex items-center gap-2 px-5 pt-[22px] pb-[9px]">
                <span className="min-w-0 flex-1 truncate text-[10.5px] font-bold tracking-[.7px] text-label uppercase">{s.label}</span>
                {s.accion && (!s.accion.perm || can(s.accion.perm)) && (
                  <Link to={s.accion.to} className="flex size-5 items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink" aria-label={s.accion.label} title={s.accion.label}>
                    <Plus className="size-3.5" strokeWidth={2.2} />
                  </Link>
                )}
              </div>
            )}
            <nav className="flex flex-col gap-2 px-2.5 py-1" aria-label={s.label ?? ariaLabel}>
              {items.map((it) => (
                <NavItem key={it.to + it.label} to={it.to} icon={it.icon} label={it.label} on={itemActivo(it, u)} extra={it.count?.(nav)} />
              ))}
              {items.length === 0 && s.vacio && <p className="px-3 py-1 text-[12.5px] text-muted">{s.vacio}</p>}
            </nav>
          </div>
        )
      })}
    </>
  )
}
