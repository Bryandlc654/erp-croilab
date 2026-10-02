import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute from '../features/auth/ProtectedRoute'
import Cargando from '../shared/ui/Cargando'
import Providers from './Providers'
import AppLayout from './layout/AppLayout'

/* Cada pantalla en su propio trozo de JS: el login no descarga el tablero y
   el tablero no descarga el login. El marco (AppLayout) va en el principal. */
const Login = lazy(() => import('../features/auth/Login'))
const Recuperar = lazy(() => import('../features/auth/Recuperar'))
const Restablecer = lazy(() => import('../features/auth/Restablecer'))
const TareasPage = lazy(() => import('../features/tareas/components/TareasPage'))
const Proximamente = lazy(() => import('./Proximamente'))

const BASE_PATH = (import.meta.env.VITE_BASE_PATH ?? '/admin').replace(/\/+$/, '') || '/'

/* Pantallas del ERP PHP que todavía no se han pasado a React. */
const PENDIENTES: [string, string][] = [
  ['/inicio', 'Dashboard'],
  ['/notificaciones', 'Notificaciones'],
  ['/clientes/*', 'Clientes'],
  ['/crm', 'CRM'],
  ['/finanzas', 'Finanzas'],
  ['/reuniones', 'Reuniones'],
  ['/calendario', 'Calendario'],
  ['/credenciales', 'Credenciales'],
  ['/chat', 'Chat'],
  ['/asistente', 'Asistente'],
  ['/soporte', 'Soporte'],
  ['/actas', 'Actas'],
  ['/perfil', 'Mi cuenta'],
  ['/ajustes', 'Gestión y ajustes'],
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
            <Route element={<ProtectedRoute />}>
              <Route element={<AppLayout />}>
                <Route path="/tareas" element={<TareasPage />} />
                {PENDIENTES.map(([path, titulo]) => (
                  <Route key={path} path={path} element={<Proximamente titulo={titulo} />} />
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
