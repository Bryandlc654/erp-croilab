import { z } from 'zod'
import { PersonaSchema } from '../../shared/schemas'

/* Solo contadores: la barra lateral ya no recibe la lista entera de clientes
   (cada sección la pide paginada al desplegarse). */
export const NavSchema = z.object({
  marca: z.string(),
  pendientes: z.number().int(),
  no_leidas: z.number().int(),
  clientes: z.object({
    total: z.number().int(),
    activos: z.number().int(),
    inactivos: z.number().int(),
  }),
})
export type Nav = z.infer<typeof NavSchema>

export const EquipoRespuesta = z.object({ items: z.array(PersonaSchema) })
