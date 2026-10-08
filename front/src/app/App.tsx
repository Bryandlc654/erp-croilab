import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from '../features/auth/ProtectedRoute'
import Cargando from '../shared/ui/Cargando'
import Providers from './Providers'
import AppLayout from './layout/AppLayout'
import type { RutaModulo } from './rutasModulo'
import { rutas as rutasTrabajo } from '../features/trabajo/rutas'
import { rutas as rutasClientes } from '../features/clientes/rutas'
import { rutas as rutasCrm } from '../features/crm/rutas'
import { rutas as rutasFinanzas } from '../features/finanzas/rutas'
import { rutas as rutasComunicacion } from '../features/comunicacion/rutas'
import { rutas as rutasEquipo, rutasPublicas as rutasPublicasEquipo } from '../features/equipo/rutas'
import { rutasPortal, rutasEditorPortal } from '../features/portal/rutas'

/* Cada pantalla en su propio trozo de JS: el login no descarga el tablero y
   el tablero no descarga el login. El marco (AppLayout) va en el principal. */
const Login = lazy(() => import('../features/auth/Login'))
const Recuperar = lazy(() => import('../features/auth/Recuperar'))
const Restablecer = lazy(() => import('../features/auth/Restablecer'))
const TareasPage = lazy(() => import('../features/tareas/components/TareasPage'))

const BASE_PATH = (import.meta.env.VITE_BASE_PATH ?? '/admin').replace(/\/+$/, '') || '/'

/* Galería de componentes para revisar el aspecto (solo en desarrollo: en el
   build de producción import.meta.env.DEV es false y el trozo ni se genera). */
const GaleriaUI = import.meta.env.DEV ? lazy(() => import('../dev/GaleriaUI')) : null

/* Rutas de cada módulo (docs/migracion/RUTAS.md): cada uno en su fichero para
   que se puedan migrar a la vez sin pisarse. Las pantallas que aún no se han
   pasado a React enseñan «Próximamente». */
const RUTAS_MODULOS: RutaModulo[] = [
  ...rutasTrabajo,
  ...rutasClientes,
  ...rutasCrm,
  ...rutasFinanzas,
  ...rutasComunicacion,
  ...rutasEquipo,
]

export default function App() {
  return (
    <Providers>
      <BrowserRouter basename={BASE_PATH} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <Suspense fallback={<Cargando />}>
          <Routes>
            <Route path="/login" element={<Login />} />
            {/* Públicas: se usan sin sesión (el enlace llega por correo). */}
            <Route path="/recuperar" element={<Recuperar />} />
            <Route path="/restablecer" element={<Restablecer />} />
            {rutasPublicasEquipo.map((r) => (
              <Route key={r.path} path={r.path} element={r.element} />
            ))}
            {/* Portal del cliente: su propia sesión (la del cliente), no la del equipo. */}
            {rutasPortal.map((r) => (
              <Route key={r.path} path={r.path} element={r.element} />
            ))}
            {GaleriaUI && <Route path="/dev/ui" element={<GaleriaUI />} />}
            <Route element={<ProtectedRoute />}>
              {/* Vista previa y editor del portal de un cliente: sesión del equipo, a pantalla completa. */}
              {rutasEditorPortal.map((r) => (
                <Route key={r.path} path={r.path} element={r.element} />
              ))}
              <Route element={<AppLayout />}>
                <Route path="/tareas" element={<TareasPage />} />
                {RUTAS_MODULOS.map((r) => (
                  <Route key={r.path} path={r.path} element={r.element} />
                ))}
                <Route path="/" element={<Navigate to="/tareas?view=mine" replace />} />
                <Route path="*" element={<Navigate to="/tareas?view=mine" replace />} />
              </Route>
            </Route>
          </Routes>
        </Suspense>
      </BrowserRouter>
    </Providers>
  )
}
