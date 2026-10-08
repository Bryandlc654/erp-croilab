import { createContext, useContext } from 'react'

/* Un <Field> reparte a su control el id de la etiqueta, el de la ayuda y si es
   inválido: así TextInput/Select quedan enlazados sin pasar ids a mano. */
export type CampoCtx = { id: string; ayudaId?: string; invalid?: boolean; required?: boolean }
export const CampoContext = createContext<CampoCtx | null>(null)

export function useCampo() {
  return useContext(CampoContext)
}

/* La rejilla de formulario dice a sus Field cuánto ocupan por defecto. */
export const RejillaContext = createContext<{ una: boolean } | null>(null)
