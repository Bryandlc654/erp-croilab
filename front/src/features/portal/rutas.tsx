import { lazy, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import type { RutaModulo } from '../../app/rutasModulo'
import { crearQueryClient } from '../../app/queryClient'
import './portal.css'

const LoginPortal = lazy(() => import('./components/acceso/LoginPortal'))
const RecuperarPortal = lazy(() => import('./components/acceso/RecuperarPortal').then((m) => ({ default: m.RecuperarPortal })))
const RestablecerPortal = lazy(() => import('./components/acceso/RecuperarPortal').then((m) => ({ default: m.RestablecerPortal })))
const PortalCliente = lazy(() => import('./components/PortalCliente'))
const PortalEquipo = lazy(() => import('./components/editor/PortalEquipo'))

/* El portal del cliente tiene su propia caché: el AuthProvider del equipo la
   vacía entera cuando /me responde 401 (que en el portal es lo normal: el
   cliente no es del equipo) y se llevaría por delante los datos del portal. */
const qcPortal = crearQueryClient()
function ConCachePortal({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qcPortal}>{children}</QueryClientProvider>
}

/* Portal del cliente (docs/migracion/RUTAS.md): PÚBLICAS, fuera de la sesión
   del equipo (la sesión del cliente la comprueba la API en cada petición). */
export const rutasPortal: RutaModulo[] = [
  { path: '/portal/login', element: <ConCachePortal><LoginPortal /></ConCachePortal> },
  { path: '/portal/recuperar', element: <ConCachePortal><RecuperarPortal /></ConCachePortal> },
  { path: '/portal/restablecer', element: <ConCachePortal><RestablecerPortal /></ConCachePortal> },
  { path: '/portal/*', element: <ConCachePortal><PortalCliente /></ConCachePortal> },
]

/* Para el equipo: vista previa y editor en vivo del portal de un cliente.
   Van DENTRO de <ProtectedRoute> (sesión del equipo) pero FUERA de <AppLayout>:
   es el portal a pantalla completa, con su barra de edición arriba. */
export const rutasEditorPortal: RutaModulo[] = [{ path: '/clientes/:id/portal/*', element: <PortalEquipo /> }]
