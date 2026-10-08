import { useState, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from '../features/auth/AuthProvider'
import { ToastProvider } from '../shared/ui/Toast'
import { ConfirmProvider } from '../shared/ui/ConfirmDialog'
import { crearQueryClient } from './queryClient'

/* El AuthProvider va dentro del QueryClientProvider: al cerrar o caducar la
   sesión vacía la caché para que nada de esa sesión se vea en la siguiente. */
export default function Providers({ children }: { children: ReactNode }) {
  const [qc] = useState(crearQueryClient)
  return (
    <QueryClientProvider client={qc}>
      <AuthProvider>
        <ToastProvider>
          <ConfirmProvider>{children}</ConfirmProvider>
        </ToastProvider>
      </AuthProvider>
    </QueryClientProvider>
  )
}
