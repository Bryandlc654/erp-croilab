import { useEffect, useMemo } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../../shared/api/client'
import Cargando from '../../../shared/ui/Cargando'
import { PORTAL_SIN_SESION, salir, useDatosPortal, type Origen } from '../api'
import { hacerRuta, PortalContext, type PortalCtx } from '../contexto'
import PortalRutas from './PortalRutas'

const ORIGEN: Origen = { tipo: 'cliente' }

/* El portal con la sesión del cliente. Sin sesión, al acceso. */
export default function PortalCliente() {
  const q = useDatosPortal(ORIGEN)
  const qc = useQueryClient()
  const nav = useNavigate()
  useEffect(() => {
    // Una acción que se encuentra la sesión caducada (contraseña cambiada…) manda al acceso.
    const fuera = () => nav('/portal/login?sesion=1', { replace: true })
    window.addEventListener(PORTAL_SIN_SESION, fuera)
    return () => window.removeEventListener(PORTAL_SIN_SESION, fuera)
  }, [nav])
  const ctx = useMemo<PortalCtx | null>(() => (q.data ? { datos: q.data, origen: ORIGEN, base: '/portal', ruta: hacerRuta('/portal'), editar: null } : null), [q.data])

  if (q.isError) {
    if (q.error instanceof ApiError && q.error.status === 401) return <Navigate to="/portal/login" replace />
    return (
      <div className="portal flex min-h-screen items-center justify-center bg-(--p-bg) p-6 text-center text-(--p-ink)">
        <div>
          <p className="text-[17px] font-bold">No hemos podido cargar tu portal</p>
          <p className="mt-1 text-[14px] text-(--p-muted)">{q.error instanceof ApiError ? q.error.message : 'Inténtalo de nuevo en un momento.'}</p>
          <button type="button" onClick={() => void q.refetch()} className="mt-4 rounded-xl bg-(--p-acc) px-4 py-2.5 font-semibold text-(--p-acc-fg)">
            Reintentar
          </button>
        </div>
      </div>
    )
  }
  if (!ctx) return <Cargando />
  const fuera = async () => {
    await salir()
    qc.removeQueries({ queryKey: ['portal'] })
    nav('/portal/login', { replace: true })
  }
  return (
    <PortalContext.Provider value={ctx}>
      <PortalRutas onSalir={() => void fuera()} />
    </PortalContext.Provider>
  )
}
