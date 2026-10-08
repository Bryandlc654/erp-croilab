import { lazy } from 'react'
import type { RutaModulo } from '../../app/rutasModulo'

const ClientesPage = lazy(() => import('./components/ClientesPage'))
const ClienteFichaPage = lazy(() => import('./components/ClienteFichaPage'))
const ClienteFormPage = lazy(() => import('./components/ClienteFormPage'))
const TiposPage = lazy(() => import('./components/TiposPage'))
const TipoFormPage = lazy(() => import('./components/TipoFormPage'))
const ServiciosPage = lazy(() => import('./components/ServiciosPage'))
const AgenciasPage = lazy(() => import('./components/AgenciasPage'))
const MetricasClientePage = lazy(() => import('./components/MetricasClientePage'))
const AvanzadoPage = lazy(() => import('./components/AvanzadoPage'))

/* Clientes (docs/migracion/RUTAS.md). Las rutas fijas (/nuevo, /tipos…) ganan
   a /:id porque react-router puntúa más los segmentos estáticos. */
export const rutas: RutaModulo[] = [
  { path: '/clientes', element: <ClientesPage /> },
  { path: '/clientes/nuevo', element: <ClienteFormPage /> },
  { path: '/clientes/tipos', element: <TiposPage /> },
  { path: '/clientes/tipos/nuevo', element: <TipoFormPage /> },
  { path: '/clientes/tipos/:id', element: <TipoFormPage /> },
  { path: '/clientes/servicios', element: <ServiciosPage /> },
  { path: '/clientes/agencias', element: <AgenciasPage /> },
  { path: '/clientes/:id', element: <ClienteFichaPage /> },
  { path: '/clientes/:id/editar', element: <ClienteFormPage /> },
  { path: '/clientes/:id/metricas', element: <MetricasClientePage /> },
  { path: '/clientes/:id/avanzado', element: <AvanzadoPage /> },
]
