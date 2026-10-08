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
  // Texto libre («Octubre 2026»): agrupa las entradas de las listas de informe.
  mes: z.string(),
  etiquetas: z.string(),
  orden: z.number().int(),
  client_name: z.string().nullable(),
  client_iniciales: z.string().nullable(),
  list_name: z.string().nullable(),
  list_tipo: z.string(),
  asignados: z.array(PersonaSchema),
})
export type Tarea = z.infer<typeof TareaSchema>

export const AdjuntoTareaSchema = z.object({
  id: IdSchema,
  nombre: z.string(),
  filename: z.string(),
  // Relativa al backend (archivo.php?d=tasks&f=…): el front le pone la URL de la API.
  url: z.string(),
  mime: z.string(),
  es_imagen: z.boolean(),
  admin_id: IdSchema.nullable(),
  created_at: z.string(),
})
export type AdjuntoTarea = z.infer<typeof AdjuntoTareaSchema>

export const PuntoSchema = z.object({ id: IdSchema, texto: z.string(), done: z.boolean(), orden: z.number().int(), asignados: z.array(IdSchema) })
export type Punto = z.infer<typeof PuntoSchema>

export const TiempoSchema = z.object({
  total_min: z.number().int(),
  reparto: z.array(z.object({ persona: PersonaSchema, minutos: z.number().int() })),
})
export type Tiempo = z.infer<typeof TiempoSchema>

export const ActividadSchema = z.object({ id: IdSchema, tipo: z.string(), detalle: z.string(), actor: z.string().nullable(), created_at: z.string() })
export type Actividad = z.infer<typeof ActividadSchema>

/* La ficha completa (/tareas/:id). La descripción llega en el formato del ERP
   (texto con marcadores, nunca HTML): se pinta con RichTextView. */
export const TareaDetalleSchema = TareaSchema.extend({
  descripcion: z.string(),
  titulo_cliente: z.string(),
  explicacion_cliente: z.string(),
  created_at: z.string(),
  updated_at: z.string(),
  actividad: z.array(ActividadSchema),
  checklist: z.array(PuntoSchema),
  adjuntos: z.array(AdjuntoTareaSchema),
  tiempo: TiempoSchema,
  permisos: z.object({ editar: z.boolean(), borrar: z.boolean(), comentar: z.boolean(), horas: z.boolean() }),
})
export type TareaDetalle = z.infer<typeof TareaDetalleSchema>

export const PuntoComentarioSchema = z.object({ texto: z.string(), done: z.boolean(), resp: IdSchema.nullable() })
export type PuntoComentario = z.infer<typeof PuntoComentarioSchema>

export const ReaccionSchema = z.object({ emoji: z.string(), n: z.number().int(), mia: z.boolean(), quienes: z.array(z.string()) })

export const ComentarioSchema = z.object({
  id: IdSchema,
  // null = «Sistema» (o una persona que ya no existe).
  autor: PersonaSchema.nullable(),
  cuerpo: z.string(),
  checklist: z.array(PuntoComentarioSchema),
  reply_to: IdSchema.nullable(),
  editado: z.boolean(),
  created_at: z.string(),
  adjuntos: z.array(AdjuntoTareaSchema),
  reacciones: z.array(ReaccionSchema),
  mio: z.boolean(),
})
export type ComentarioTarea = z.infer<typeof ComentarioSchema>

export const ComentariosRespuesta = z.object({ items: z.array(ComentarioSchema), n: z.number().int(), ultimo: z.number().int() })
export const ComentarioRespuesta = z.object({ comentario: ComentarioSchema })

export const TareasPagina = paginaSchema(TareaSchema).extend({
  // Lista que se está viendo en la vista de cliente (sin `list` la API usa la primera).
  list_id: IdSchema.nullish(),
})
export type TareasPagina = z.infer<typeof TareasPagina>

export const TareaRespuesta = z.object({ tarea: TareaSchema })
export const TareaDetalleRespuesta = z.object({ tarea: TareaDetalleSchema })
export const BorrarRespuesta = z.object({ papelera_id: IdSchema })
export const RestaurarRespuesta = z.object({ id: IdSchema })
export const ChecklistRespuesta = z.object({ checklist: z.array(PuntoSchema) })
export const AdjuntosRespuesta = z.object({ adjuntos: z.array(AdjuntoTareaSchema) })
export const ArchivosRespuesta = z.object({ archivos: z.array(z.object({ fn: z.string(), nombre: z.string(), imagen: z.boolean(), url: z.string() })) })
export const TiempoRespuesta = z.object({ tiempo: TiempoSchema })
export const ReaccionesRespuesta = z.object({ reacciones: z.array(ReaccionSchema) })
export const PuntosComentarioRespuesta = z.object({ checklist: z.array(PuntoComentarioSchema) })
export const MesesRespuesta = z.object({ items: z.array(z.object({ mes: z.string(), n: z.number().int() })) })

export const ListaSchema = z.object({ id: IdSchema, client_id: IdSchema, nombre: z.string(), es_cliente: z.boolean(), tipo: z.string(), orden: z.number().int() })
export type ListaTareas = z.infer<typeof ListaSchema>
export const ListasRespuesta = z.object({ listas: z.array(ListaSchema), id: IdSchema.nullish() })
export const ListaRespuesta = z.object({ lista: ListaSchema })
export const InformeSchema = z.object({ mes: z.string(), texto: z.string(), publicado: z.boolean(), id: IdSchema.nullable(), updated_at: z.string().nullable() })
export type InformeMes = z.infer<typeof InformeSchema>
export const InformeRespuesta = z.object({ informe: InformeSchema })
export const VacioRespuesta = z.object({})

/* Campos que se cambian en línea desde la fila. */
export type CampoEnLinea = 'estado' | 'prioridad' | 'due_date' | 'responsable_id' | 'asignados'
/* Lo que se puede mandar en PATCH /tareas/:id. */
export type CambiosTarea = Partial<Pick<Tarea, 'estado' | 'prioridad' | 'due_date' | 'fecha_inicio' | 'responsable_id' | 'titulo' | 'etiquetas' | 'mes' | 'visible_cliente'>> & {
  asignados?: number[]
  descripcion?: string
  titulo_cliente?: string
  explicacion_cliente?: string
}
