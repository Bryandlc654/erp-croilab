import { createContext, useContext } from 'react'

export type OpcionesConfirm = {
  title?: string
  message?: string
  okLabel?: string
  cancelLabel?: string
  /* Acción destructiva: botón rojo y «Eliminar» por defecto. */
  danger?: boolean
}
export type OpcionesPrompt = { title: string; value?: string; placeholder?: string; message?: string; okLabel?: string; cancelLabel?: string }
export type OpcionesAlert = { title?: string; message: string; okLabel?: string }

export type ConfirmCtx = {
  /* true si acepta, false si cancela (Esc, máscara, «Cancelar»). */
  confirm: (o: OpcionesConfirm | string) => Promise<boolean>
  /* El texto escrito, o null si cancela o lo deja vacío. */
  prompt: (o: OpcionesPrompt) => Promise<string | null>
  alert: (o: OpcionesAlert | string) => Promise<void>
}

/* Separado del proveedor: los ficheros de componentes solo exportan componentes. */
export const ConfirmContext = createContext<ConfirmCtx | null>(null)

/* Diálogos propios del ERP (erpConfirm/erpPrompt/erpAlert): nunca confirm() nativo. */
export function useConfirm() {
  const v = useContext(ConfirmContext)
  if (!v) throw new Error('useConfirm must be used within ConfirmProvider')
  return v
}
