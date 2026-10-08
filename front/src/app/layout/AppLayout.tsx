import { lazy, Suspense, useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useNav } from '../../features/nav/api'
import Cargando from '../../shared/ui/Cargando'
import IconRail from './IconRail'
import Sidebar from './Sidebar'
import Topbar from './Topbar'
import Avisador from '../../features/trabajo/components/Avisador'
import { moduloDe } from '../navegacion'

/* El avisador del chat (y todo el código del chat que arrastra) va en su propio trozo. */
const AvisadorChat = lazy(() => import('../../features/comunicacion/AvisadorChat'))

export default function AppLayout() {
  const { data: nav } = useNav()
  const location = useLocation()
  // En móvil la barra lateral se abre como cajón. Se guarda en qué navegación se
  // abrió: al cambiar de ruta deja de coincidir y el cajón se cierra solo.
  const [abiertoEn, setAbiertoEn] = useState<string | null>(null)
  const cajonAbierto = abiertoEn === location.key
  // Reuniones, Calendario, Chat… van sin barra lateral en escritorio (como en el ERP).
  // La ficha de una tarea también: necesita el ancho para su panel de actividad
  // (el antiguo la recogía, side-collapse).
  const fichaTarea = /^\/tareas\/\d+/.test(location.pathname)
  const solo = moduloDe(location.pathname).solo === true || fichaTarea

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
        <IconRail marca={nav?.marca ?? 'Croilab'} marcaInfo={nav?.marca_info} />
      </div>
      {!solo && (
        <div className="hidden lg:flex">
          <Sidebar />
        </div>
      )}
      {cajonAbierto && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menú">
          <button type="button" className="absolute inset-0 bg-black/20 dark:bg-black/50" onClick={() => setAbiertoEn(null)} aria-label="Cerrar menú" />
          <div id="menu-lateral" className="relative flex h-full w-fit bg-page shadow-2xl">
            <div className="md:hidden">
              <IconRail marca={nav?.marca ?? 'Croilab'} marcaInfo={nav?.marca_info} flyouts={false} />
            </div>
            <Sidebar />
          </div>
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col overflow-x-clip">
        <Topbar menuAbierto={cajonAbierto} onMenu={() => setAbiertoEn(location.key)} />
        <main className="flex-1 px-4 py-5 md:px-6 md:py-6 lg:px-[52px] lg:pt-9 lg:pb-20">
          {/* Cada pantalla entra subiendo un poco (fadeUp del ERP); la key la
              reinicia al cambiar de pantalla, no al cambiar de filtro. */}
          <div key={location.pathname} className="motion-safe:animate-fade-up">
            <Suspense fallback={<Cargando />}>
              <Outlet />
            </Suspense>
          </div>
          {/* Mensajes nuevos del chat (pop-ups arriba a la derecha; los avisos van debajo). */}
          <Suspense fallback={null}>
            <AvisadorChat />
          </Suspense>
        </main>
      </div>
      {/* Avisos nuevos: globo de la campana y pop-ups (sondeo cada 5 s). */}
      <Avisador />
    </div>
  )
}
