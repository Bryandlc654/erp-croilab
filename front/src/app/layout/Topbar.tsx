import { useEffect, useRef, type ReactNode } from 'react'
import { Eye, Menu as MenuIcono, Moon, Search, Sun, UserRound } from 'lucide-react'
import { useTema } from '../../shared/lib/theme'
import { useAuth } from '../../features/auth/useAuth'

const BTN =
  'inline-flex h-[35px] items-center gap-[7px] rounded-[9px] border border-line bg-page px-[13px] text-[12.5px] font-semibold text-[#4c515b] transition-colors hover:border-[#dcdcde] hover:bg-soft hover:text-ink dark:text-zinc-300 dark:hover:border-zinc-600'

/* El portal del cliente y la gestión del equipo aún no tienen pantalla en
   React: se enseñan como en el ERP, pero desactivados hasta que existan. */
function Proximamente({ children, className }: { children: ReactNode; className: string }) {
  return (
    <span aria-disabled="true" title="Disponible próximamente" className={`${className} cursor-not-allowed opacity-60`}>
      {children}
    </span>
  )
}

/* onMenu abre la barra lateral como cajón: por debajo de lg no cabe fija. */
export default function Topbar({ menuAbierto, onMenu }: { menuAbierto: boolean; onMenu: () => void }) {
  const { tema, alternar } = useTema()
  const { can } = useAuth()
  const buscar = useRef<HTMLInputElement>(null)

  // Ctrl/Cmd + K enfoca el buscador, como indica la etiqueta.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        buscar.current?.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  return (
    <header className="sticky top-0 z-10 flex h-[53px] shrink-0 items-center justify-between gap-3 border-b border-line bg-page/95 px-4 backdrop-blur md:gap-4 md:px-6">
      {/* En escritorio el botón no existe (lg:hidden) y el buscador queda igual que antes. */}
      <div className="flex min-w-0 flex-1 items-center gap-2.5">
        <button
          type="button"
          onClick={onMenu}
          className={`${BTN} shrink-0 px-[11px] lg:hidden`}
          aria-label="Abrir menú"
          aria-expanded={menuAbierto}
          aria-controls="menu-lateral"
          title="Menú"
        >
          <MenuIcono className="size-4" strokeWidth={1.8} />
        </button>
        <label className="flex h-[35px] w-full max-w-[330px] cursor-text items-center gap-2.5 rounded-[9px] border border-line bg-soft px-3 text-muted transition-colors focus-within:border-[#dcdcde] focus-within:bg-page hover:border-[#dcdcde] hover:bg-page dark:focus-within:border-zinc-600">
          <Search className="size-[15px] shrink-0" strokeWidth={2} />
          <input
            ref={buscar}
            type="search"
            placeholder="Buscar clientes, tareas, facturas..."
            aria-label="Buscar"
            className="min-w-0 flex-1 bg-transparent text-[12.5px] text-ink placeholder:text-muted focus:outline-none"
          />
          <kbd className="hidden shrink-0 rounded-md border border-line bg-page px-1.5 py-0.5 font-sans text-[10.5px] font-bold text-muted sm:inline">
            Ctrl K
          </kbd>
        </label>
      </div>

      <div className="flex shrink-0 items-center gap-2.5">
        <button
          type="button"
          onClick={alternar}
          className={`${BTN} px-[11px]`}
          aria-label={tema === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
          title={tema === 'dark' ? 'Modo claro' : 'Modo oscuro'}
        >
          {tema === 'dark' ? <Sun className="size-4" strokeWidth={1.8} /> : <Moon className="size-4" strokeWidth={1.8} />}
        </button>
        <Proximamente className={`${BTN} max-md:hidden`}>
          <Eye className="size-[15px]" strokeWidth={1.8} /> Portal cliente
        </Proximamente>
        {can('equipo.gestionar') && (
          <Proximamente className={`${BTN} max-md:hidden`}>
            <UserRound className="size-[15px]" strokeWidth={1.8} /> Administrar Roles
          </Proximamente>
        )}
      </div>
    </header>
  )
}
