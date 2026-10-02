import { createContext, useContext } from 'react'

export type AccionToast = { label: string; fn: () => void }
export type OpcionesToast = { tipo?: 'ok' | 'error'; accion?: AccionToast }
export type ToastCtx = { aviso: (msg: string, opts?: OpcionesToast) => void }

/* Separado de <ToastProvider>: los ficheros de componentes solo exportan
   componentes (lo pide el refresco en caliente de Vite). */
export const ToastContext = createContext<ToastCtx | null>(null)

export function useToast() {
  const v = useContext(ToastContext)
  if (!v) throw new Error('useToast must be used within ToastProvider')
  return v
}
