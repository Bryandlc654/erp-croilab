import { Suspense, useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useNav } from '../../features/nav/api'
import Cargando from '../../shared/ui/Cargando'
import IconRail from './IconRail'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

export default function AppLayout() {
  const { data: nav } = useNav()
  const location = useLocation()
  // En móvil la barra lateral se abre como cajón. Se guarda en qué navegación se
  // abrió: al cambiar de ruta deja de coincidir y el cajón se cierra solo.
  const [abiertoEn, setAbiertoEn] = useState<string | null>(null)
  const cajonAbierto = abiertoEn === location.key

  useEffect(() => {
    if (!cajonAbierto) return
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && setAbiertoEn(null)
    document.addEventListener('keydown', esc)
    return () => document.removeEventListener('keydown', esc)
  }, [cajonAbierto])

  return (
    <div className="flex min-h-screen bg-page text-ink">
      {/* En móvil la barra de iconos va dentro del cajón: aquí quitaría 66px de pantalla. */}
      <div className="max-md:hidden">
        <IconRail marca={nav?.marca ?? 'Croilab'} />
      </div>
      <div className="hidden lg:flex">
        <Sidebar />
      </div>
      {cajonAbierto && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menú">
          <button type="button" className="absolute inset-0 bg-black/20 dark:bg-black/50" onClick={() => setAbiertoEn(null)} aria-label="Cerrar menú" />
          <div id="menu-lateral" className="relative flex h-full w-fit bg-page shadow-2xl">
            <div className="md:hidden">
              <IconRail marca={nav?.marca ?? 'Croilab'} />
            </div>
            <Sidebar />
          </div>
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col overflow-x-clip">
        <Topbar menuAbierto={cajonAbierto} onMenu={() => setAbiertoEn(location.key)} />
        <main className="flex-1 px-4 py-5 md:px-6 md:py-6 lg:px-[52px] lg:py-9">
          <Suspense fallback={<Cargando />}>
            <Outlet />
          </Suspense>
        </main>
      </div>
    </div>
  )
}
