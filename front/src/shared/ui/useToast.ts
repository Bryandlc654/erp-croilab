import { createContext, useContext } from 'react'

export type AccionToast = { label: string; fn: () => void }
/* ok: con el ✓ verde · error: rojo con «!» · plain: sin icono. `ms` cambia la
   duración (2,2 s por defecto; 7 s si lleva acción, como «Deshacer»). */
export type OpcionesToast = { tipo?: 'ok' | 'error' | 'plain'; accion?: AccionToast; ms?: number }
export type ToastCtx = { aviso: (msg: string, opts?: OpcionesToast) => void }

/* Separado de <ToastProvider>: los ficheros de componentes solo exportan
   componentes (lo pide el refresco en caliente de Vite). */
export const ToastContext = createContext<ToastCtx | null>(null)

export function useToast() {
  const v = useContext(ToastContext)
  if (!v) throw new Error('useToast must be used within ToastProvider')
  return v
}
