import { lazy } from 'react'
import { Navigate } from 'react-router-dom'
import type { RutaModulo } from '../../app/rutasModulo'

const ContactosPage = lazy(() => import('./components/ContactosPage'))
const NegocioPage = lazy(() => import('./components/NegocioPage'))
const DashboardPage = lazy(() => import('./components/DashboardPage'))
const ReportingPage = lazy(() => import('./components/ReportingPage'))
const ListasPage = lazy(() => import('./components/ListasPage'))
const ImportarPage = lazy(() => import('./components/ImportarPage'))

/* CRM (docs/migracion/RUTAS.md). La ficha de un contacto se abre encima de la
   tabla de Contactos: /crm/contactos/:id es la misma pantalla con la ficha. */
export const rutas: RutaModulo[] = [
  { path: '/crm', element: <ContactosPage /> },
  { path: '/crm/contactos/:id', element: <ContactosPage /> },
  { path: '/crm/negocio', element: <NegocioPage /> },
  { path: '/crm/dashboard', element: <DashboardPage /> },
  { path: '/crm/reporting', element: <ReportingPage /> },
  { path: '/crm/listas', element: <ListasPage /> },
  { path: '/crm/listas/:id', element: <ListasPage /> },
  { path: '/crm/importar', element: <ImportarPage /> },
  { path: '/crm/*', element: <Navigate to="/crm" replace /> },
]
