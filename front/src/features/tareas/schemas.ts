import { z } from 'zod'
import { IdSchema, PersonaSchema, paginaSchema } from '../../shared/schemas'

export const EstadoSchema = z.enum(['pendiente', 'en proceso', 'atemporal', 'completada'])
export type Estado = z.infer<typeof EstadoSchema>

// YYYY-MM-DD o null.
const Fecha = z.string().nullable()

export const TareaSchema = z.object({
  id: IdSchema,
  client_id: IdSchema,
  list_id: IdSchema,
  titulo: z.string(),
  estado: EstadoSchema,
  // 0 Ninguna … 4 Urgente.
  prioridad: z.number().int().min(0).max(4),
  responsable_id: IdSchema.nullable(),
  due_date: Fecha,
  fecha_inicio: Fecha,
  visible_cliente: z.boolean(),
  client_name: z.string().nullable(),
  client_iniciales: z.string().nullable(),
  list_name: z.string().nullable(),
  asignados: z.array(PersonaSchema),
})
export type Tarea = z.infer<typeof TareaSchema>

export const TareaDetalleSchema = TareaSchema.extend({
  // HTML del editor del ERP: se enseña como texto, nunca se inyecta.
  descripcion: z.string().nullable(),
  etiquetas: z.string().nullable(),
  mes: z.string().nullable(),
  titulo_cliente: z.string().nullable(),
  explicacion_cliente: z.string().nullable(),
  comentarios: z.array(
    z.object({
      id: IdSchema,
      admin_id: IdSchema.nullable(),
      // null si la persona ya no existe.
      username: z.string().nullable(),
      cuerpo: z.string(),
      created_at: z.string(),
    }),
  ),
  checklist: z.array(z.object({ id: IdSchema, texto: z.string(), done: z.boolean() })),
  adjuntos: z.array(z.object({ id: IdSchema, nombre: z.string().nullable(), filename: z.string() })),
})
export type TareaDetalle = z.infer<typeof TareaDetalleSchema>

export const TareasPagina = paginaSchema(TareaSchema).extend({
  // Lista que se está viendo en la vista de cliente (sin `list` la API usa la primera).
  list_id: IdSchema.nullish(),
})
export type TareasPagina = z.infer<typeof TareasPagina>

export const TareaRespuesta = z.object({ tarea: TareaSchema })
export const TareaDetalleRespuesta = z.object({ tarea: TareaDetalleSchema })
export const BorrarRespuesta = z.object({ papelera_id: IdSchema })
export const RestaurarRespuesta = z.object({ id: IdSchema })

/* Campos que se cambian en línea desde la fila. */
export type CampoEnLinea = 'estado' | 'prioridad' | 'due_date' | 'responsable_id'
export type CambiosTarea = Partial<Pick<Tarea, CampoEnLinea>>
