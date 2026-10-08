import { lazy } from 'react'
import type { RutaModulo } from '../../app/rutasModulo'

const Proximamente = lazy(() => import('../../app/Proximamente'))
const AgenciaPage = lazy(() => import('./pages/AgenciaPage'))
const EquipoPage = lazy(() => import('./pages/EquipoPage'))
const MiembroPage = lazy(() => import('./pages/MiembroPage'))
const RolesPage = lazy(() => import('./pages/RolesPage'))
const BovedaPage = lazy(() => import('./pages/BovedaPage'))
const PortalContactoPage = lazy(() => import('./pages/PortalContactoPage'))
const PortalVideosPage = lazy(() => import('./pages/PortalVideosPage'))
const PortalMetricasPage = lazy(() => import('./pages/PortalMetricasPage'))
const ReglasPage = lazy(() => import('./pages/ReglasPage'))
const IntegracionesPage = lazy(() => import('./pages/IntegracionesPage'))
const PerfilPage = lazy(() => import('./pages/PerfilPage'))
const RegistroPage = lazy(() => import('./pages/RegistroPage'))

/* Equipo y ajustes: agencia, equipo, roles, bóveda, portal, integraciones y
   perfiles (docs/migracion/RUTAS.md). Van dentro de la sesión. */
export const rutas: RutaModulo[] = [
  { path: '/credenciales', element: <BovedaPage /> },
  { path: '/credenciales/:id', element: <BovedaPage /> },
  { path: '/perfil', element: <PerfilPage /> },
  { path: '/perfil/:id', element: <PerfilPage /> },
  { path: '/ajustes', element: <AgenciaPage /> },
  { path: '/ajustes/equipo', element: <EquipoPage /> },
  { path: '/ajustes/equipo/:id', element: <MiembroPage /> },
  { path: '/ajustes/roles', element: <RolesPage /> },
  { path: '/ajustes/boveda', element: <BovedaPage /> },
  { path: '/ajustes/boveda/:id', element: <BovedaPage /> },
  { path: '/ajustes/portal/contacto', element: <PortalContactoPage /> },
  { path: '/ajustes/portal/videos', element: <PortalVideosPage /> },
  { path: '/ajustes/portal/metricas', element: <PortalMetricasPage /> },
  { path: '/ajustes/reglas', element: <ReglasPage /> },
  { path: '/ajustes/integraciones', element: <IntegracionesPage /> },
  { path: '/ajustes/*', element: <Proximamente titulo="Gestión y ajustes" /> },
]

/* Rutas públicas (sin sesión): App.tsx tiene que montarlas FUERA de
   <ProtectedRoute>, junto a /login, /recuperar y /restablecer. */
export const rutasPublicas: RutaModulo[] = [{ path: '/registro', element: <RegistroPage /> }]
