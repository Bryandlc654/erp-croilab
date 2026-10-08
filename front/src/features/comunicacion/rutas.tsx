import { lazy } from 'react'
import type { RutaModulo } from '../../app/rutasModulo'

const ChatPage = lazy(() => import('./chat/ChatPage'))
const SoportePage = lazy(() => import('./soporte/SoportePage'))
const TicketPage = lazy(() => import('./soporte/TicketPage'))
const ReunionesPage = lazy(() => import('./reuniones/ReunionesPage'))
const CalendarioPage = lazy(() => import('./calendario/CalendarioPage'))
const AsistentePage = lazy(() => import('./asistente/AsistentePage'))

/* Comunicación: chat, soporte, reuniones, calendario y asistente (docs/migracion/RUTAS.md). */
export const rutas: RutaModulo[] = [
  { path: '/reuniones', element: <ReunionesPage /> },
  { path: '/calendario', element: <CalendarioPage /> },
  { path: '/chat', element: <ChatPage /> },
  { path: '/chat/:sala', element: <ChatPage /> },
  { path: '/asistente', element: <AsistentePage /> },
  { path: '/soporte', element: <SoportePage /> },
  { path: '/soporte/:id', element: <TicketPage /> },
]
