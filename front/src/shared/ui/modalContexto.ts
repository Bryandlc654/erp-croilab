import { createContext, useContext } from 'react'

/* El Modal reparte el id de su título (aria-labelledby) y cómo cerrarse. */
export const ModalContext = createContext<{ tituloId: string; cerrar: () => void } | null>(null)

export function useModalActual() {
  return useContext(ModalContext)
}
