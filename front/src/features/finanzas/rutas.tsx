import { lazy } from 'react'
import type { RutaModulo } from '../../app/rutasModulo'

const ResumenPage = lazy(() => import('./pages/ResumenPage'))
const FacturasPage = lazy(() => import('./pages/FacturasPage'))
const FacturaPage = lazy(() => import('./pages/FacturaPage'))
const FacturaEditorPage = lazy(() => import('./pages/FacturaEditorPage'))
const PorClientePage = lazy(() => import('./pages/PorClientePage'))
const ProgramacionesPage = lazy(() => import('./pages/ProgramacionesPage'))
const ContabilidadPage = lazy(() => import('./pages/ContabilidadPage'))
const AnalisisPage = lazy(() => import('./pages/AnalisisPage'))
const HorasPage = lazy(() => import('./pages/HorasPage'))
const ProyectosPage = lazy(() => import('./pages/ProyectosPage'))
const ProyectoPage = lazy(() => import('./pages/ProyectoPage'))
const PreciosPage = lazy(() => import('./pages/PreciosPage'))
const AjustesFacturacionPage = lazy(() => import('./pages/AjustesFacturacionPage'))
const Proximamente = lazy(() => import('../../app/Proximamente'))

/* Finanzas (y Ajustes › Facturación). docs/migracion/RUTAS.md. Las rutas fijas
   (/nueva, /analisis) ganan a las de parámetro. Los niveles de Facturas
   (emisor, ingresos/gastos, mes) van en la query: ?em=&tipo=&mes=. */
export const rutas: RutaModulo[] = [
  { path: '/finanzas', element: <ResumenPage /> },
  { path: '/finanzas/facturas', element: <FacturasPage /> },
  { path: '/finanzas/facturas/nueva', element: <FacturaEditorPage /> },
  { path: '/finanzas/facturas/:id', element: <FacturaPage /> },
  { path: '/finanzas/facturas/:id/editar', element: <FacturaEditorPage /> },
  { path: '/finanzas/clientes', element: <PorClientePage /> },
  { path: '/finanzas/programaciones', element: <ProgramacionesPage /> },
  { path: '/finanzas/contabilidad', element: <ContabilidadPage /> },
  { path: '/finanzas/contabilidad/analisis', element: <AnalisisPage /> },
  { path: '/finanzas/horas', element: <HorasPage /> },
  { path: '/finanzas/proyectos', element: <ProyectosPage /> },
  { path: '/finanzas/proyectos/:id', element: <ProyectoPage /> },
  { path: '/finanzas/precios', element: <PreciosPage /> },
  { path: '/finanzas/*', element: <Proximamente titulo="Finanzas" /> },
  { path: '/ajustes/facturacion', element: <AjustesFacturacionPage /> },
]
