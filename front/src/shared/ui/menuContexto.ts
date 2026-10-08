import { createContext, useContext } from 'react'

/* Lo que un MenuItem necesita de su menú: cerrarlo tras elegir. */
export const MenuContext = createContext<{ cerrar: () => void } | null>(null)

export function useMenuActual() {
  return useContext(MenuContext)
}
