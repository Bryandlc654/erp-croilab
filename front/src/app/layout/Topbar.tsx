import { useCallback, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Eye, Menu as MenuIcono, Moon, Search, Sun, UserRound } from 'lucide-react'
import { useTema } from '../../shared/lib/theme'
import { useAuth } from '../../features/auth/useAuth'
import CommandPalette from '../../shared/ui/CommandPalette'
import { buscarPaleta } from '../../features/trabajo/paleta'
import { useHotkeys } from '../../shared/lib/useHotkeys'

const BTN =
  'inline-flex h-[35px] items-center gap-[7px] rounded-[9px] border border-line bg-page px-[13px] text-[12.5px] font-semibold text-[#4c515b] transition-colors hover:border-[#dcdcde] hover:bg-soft hover:text-ink dark:text-zinc-300 dark:hover:border-zinc-600'

/* onMenu abre la barra lateral como cajón: por debajo de lg no cabe fija. */
export default function Topbar({ menuAbierto, onMenu }: { menuAbierto: boolean; onMenu: () => void }) {
  const { tema, alternar } = useTema()
  const { can } = useAuth()
  const navigate = useNavigate()
  const [paleta, setPaleta] = useState<{ q: string } | null>(null)
  const cerrarPaleta = useCallback(() => setPaleta(null), [])

  // Ctrl/⌘ + K desde cualquier sitio; «/» si no se está escribiendo en un campo.
  useHotkeys({
    'mod+k': (e) => {
      e.preventDefault()
      setPaleta((p) => (p ? null : { q: '' }))
    },
    '/': (e) => {
      e.preventDefault()
      setPaleta({ q: '' })
    },
  })

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
        <button
          type="button"
          onClick={() => setPaleta({ q: '' })}
          aria-haspopup="dialog"
          aria-keyshortcuts="Control+K /"
          className="flex h-[35px] w-full max-w-[330px] cursor-text items-center gap-2.5 rounded-[9px] border border-line bg-soft px-3 text-left text-muted transition-colors hover:border-[#dcdcde] hover:bg-page dark:hover:border-zinc-600"
        >
          <Search className="size-[15px] shrink-0" strokeWidth={2} />
          <span className="min-w-0 flex-1 truncate text-[12.5px] text-muted">Buscar clientes, tareas, facturas...</span>
          <kbd className="hidden shrink-0 rounded-md border border-line bg-page px-1.5 py-0.5 font-sans text-[10.5px] font-bold text-muted sm:inline">
            Ctrl K
          </kbd>
        </button>
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
        {/* Con la sesión del equipo, el acceso al portal enseña los clientes para ver el suyo. */}
        {can('ver.clientes') && (
          <Link to="/portal/login" className={`${BTN} max-md:hidden`}>
            <Eye className="size-[15px]" strokeWidth={1.8} /> Portal cliente
          </Link>
        )}
        {can('roles.gestionar') && (
          <Link to="/ajustes/roles" className={`${BTN} max-md:hidden`}>
            <UserRound className="size-[15px]" strokeWidth={1.8} /> Administrar Roles
          </Link>
        )}
      </div>
      <CommandPalette
        open={paleta !== null}
        onClose={cerrarPaleta}
        initialQuery={paleta?.q}
        buscar={buscarPaleta}
        placeholder="Buscar clientes, tareas, contactos, negocios, facturas…"
        onVerTodos={(q) => navigate(`/buscar?q=${encodeURIComponent(q)}`)}
      />
    </header>
  )
}
