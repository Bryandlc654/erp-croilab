import type { ReactNode } from 'react'
import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { useClasePortal } from '../clasePortal'
import { usePortal } from '../contexto'
import { vistaVisible, type Vista } from '../logica'
import FacturaHoja from './FacturaHoja'
import PortalLayout from './PortalLayout'
import Accesos from './vistas/Accesos'
import { Reuniones, Soporte, TicketDetalle } from './vistas/Comunicacion'
import { Facturas, Informes, Plan } from './vistas/Documentos'
import Inicio from './vistas/Inicio'
import { Metodo, Servicio } from './vistas/Metodo'
import Metricas from './vistas/Metricas'
import Progreso from './vistas/Progreso'
import Tareas from './vistas/Tareas'

/* Una vista que el tipo de cliente oculta lleva a Inicio. */
function Solo({ vista, children }: { vista: Vista; children: ReactNode }) {
  const { datos, base } = usePortal()
  return vistaVisible(vista, datos.secciones) ? <>{children}</> : <Navigate to={base} replace />
}

/* Rutas del portal relativas a su raíz (/portal o /clientes/:id/portal). */
export default function PortalRutas({ onSalir, arriba = 0 }: { onSalir: (() => void) | null; arriba?: number }) {
  const { base } = usePortal()
  useClasePortal()
  return (
    <Routes>
      <Route
        element={
          <PortalLayout onSalir={onSalir} arriba={arriba}>
            <Outlet />
          </PortalLayout>
        }
      >
        <Route index element={<Inicio />} />
        <Route path="metricas" element={<Solo vista="metricas"><Metricas /></Solo>} />
        <Route path="tareas" element={<Tareas />} />
        <Route path="progreso" element={<Solo vista="progreso"><Progreso /></Solo>} />
        <Route path="reuniones" element={<Reuniones />} />
        <Route path="soporte" element={<Soporte />} />
        <Route path="soporte/:ticket" element={<TicketDetalle />} />
        <Route path="informes" element={<Solo vista="informes"><Informes /></Solo>} />
        <Route path="facturas" element={<Facturas />} />
        <Route path="metodo" element={<Solo vista="metodo"><Metodo /></Solo>} />
        <Route path="metodo/:servicio" element={<Solo vista="metodo"><Servicio /></Solo>} />
        <Route path="accesos" element={<Solo vista="accesos"><Accesos /></Solo>} />
        <Route path="plan" element={<Solo vista="plan"><Plan /></Solo>} />
      </Route>
      {/* La hoja de la factura va sin el marco del portal: es un papel para imprimir. */}
      <Route
        path="facturas/:factura"
        element={
          <div className="portal min-h-screen bg-(--p-bg)">
            <FacturaHoja />
          </div>
        }
      />
      <Route path="*" element={<Navigate to={base} replace />} />
    </Routes>
  )
}
