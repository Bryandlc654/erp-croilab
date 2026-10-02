import { z } from 'zod'
import { IdSchema, paginaSchema } from '../../shared/schemas'

export const ListaSchema = z.object({
  id: IdSchema,
  nombre: z.string(),
  tipo: z.enum(['tareas', 'informe']),
  // Tareas sin completar visibles para quien pregunta.
  pend: z.number().int(),
})
export type Lista = z.infer<typeof ListaSchema>

export const ClienteSchema = z.object({
  id: IdSchema,
  name: z.string(),
  iniciales: z.string().nullable(),
  activo: z.boolean(),
  // Solo viene con con_listas=1 (lista) y siempre en el detalle.
  listas: z.array(ListaSchema).optional(),
})
export type Cliente = z.infer<typeof ClienteSchema>

export const ClientesPagina = paginaSchema(ClienteSchema)
export type ClientesPagina = z.infer<typeof ClientesPagina>

export const ClienteRespuesta = z.object({ cliente: ClienteSchema.extend({ listas: z.array(ListaSchema) }) })
