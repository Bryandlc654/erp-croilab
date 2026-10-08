import { lazy } from 'react'
import type { RutaModulo } from '../../app/rutasModulo'

const InicioPage = lazy(() => import('./components/InicioPage'))
const NotificacionesPage = lazy(() => import('./components/NotificacionesPage'))
const BuscarPage = lazy(() => import('./components/BuscarPage'))
const ActasPage = lazy(() => import('./components/ActasPage'))
const ActaPage = lazy(() => import('./components/ActaPage'))
const PapeleraPage = lazy(() => import('./components/PapeleraPage'))
const TareaPage = lazy(() => import('../tareas/components/ficha/TareaPage'))

/* Trabajo: inicio, avisos, búsqueda, ficha de tarea, actas y papelera (el
   tablero /tareas está en App.tsx). */
export const rutas: RutaModulo[] = [
  { path: '/inicio', element: <InicioPage /> },
  { path: '/notificaciones', element: <NotificacionesPage /> },
  { path: '/buscar', element: <BuscarPage /> },
  { path: '/tareas/:id', element: <TareaPage /> },
  { path: '/actas', element: <ActasPage /> },
  { path: '/actas/nueva', element: <ActaPage modo="nueva" /> },
  { path: '/actas/:id', element: <ActaPage modo="ver" /> },
  { path: '/actas/:id/editar', element: <ActaPage modo="editar" /> },
  { path: '/ajustes/papelera', element: <PapeleraPage /> },
]
