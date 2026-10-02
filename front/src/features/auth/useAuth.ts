import { createContext, useContext } from 'react'
import type { Me } from './schemas'

export type AuthCtx = {
  me: Me | null
  loading: boolean
  login: (u: string, p: string) => Promise<{ ok: boolean; msg?: string }>
  logout: () => Promise<void>
  refresh: () => Promise<void>
  /* ¿Tiene este permiso? Solo para ocultar lo que no puede usar: la API lo
     vuelve a comprobar en cada petición. */
  can: (permiso: string) => boolean
}

/* Separado de <AuthProvider>: los ficheros de componentes solo exportan
   componentes (lo pide el refresco en caliente de Vite). */
export const AuthContext = createContext<AuthCtx | null>(null)

export function useAuth() {
  const v = useContext(AuthContext)
  if (!v) throw new Error('useAuth must be used within AuthProvider')
  return v
}
