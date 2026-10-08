import { z } from 'zod'
import { IdSchema, PersonaSchema, paginaSchema } from '../../shared/schemas'
import { EstadoSchema } from '../tareas/schemas'

/* ---------- Inicio ---------- */

export const TareaInicioSchema = z.object({
  id: IdSchema,
  titulo: z.string(),
  estado: EstadoSchema,
  prioridad: z.number().int(),
  due_date: z.string().nullable(),
  cliente: z.string(),
  asignados: z.array(PersonaSchema),
})
export type TareaInicio = z.infer<typeof TareaInicioSchema>

export const ItemCalendarioSchema = z.object({ dia: z.string(), titulo: z.string(), sub: z.string(), tipo: z.enum(['tarea', 'evento']), hora: z.string(), url: z.string() })
export type ItemCalendario = z.infer<typeof ItemCalendarioSchema>

export const InicioSchema = z.object({
  hoy: z.string(),
  // null = sin permiso para verlo.
  kpis: z.object({ clientes_activos: z.number().int().nullable(), cobrado_mes: z.number().int().nullable() }),
  // Lo que pide atención en otros módulos. null = sin permiso para ese módulo;
  // los importes (céntimos) son null sin ver.importes.
  pulso: z.object({
    tickets: z.object({ abiertos: z.number().int(), sin_asignar: z.number().int(), mios: z.number().int() }).nullable(),
    crm_hoy: z.object({ total: z.number().int(), atrasados: z.number().int() }).nullable(),
    cobros: z
      .object({
        pendiente: z.object({ n: z.number().int(), total: z.number().int().nullable() }),
        vencidas: z.object({ n: z.number().int(), total: z.number().int().nullable() }),
      })
      .nullable(),
    chat_no_leidos: z.number().int().nullable(),
  }),
  tareas: z
    .object({
      en_proceso: z.array(TareaInicioSchema),
      atrasadas: z.array(TareaInicioSchema),
      atrasadas_total: z.number().int(),
      completadas: z.array(TareaInicioSchema),
    })
    .nullable(),
  hoy_tareas: z.array(TareaInicioSchema),
  calendario: z.array(ItemCalendarioSchema),
})
export type Inicio = z.infer<typeof InicioSchema>

export const AgendaSchema = z.object({
  conectado: z.boolean(),
  hoy: z.array(ItemCalendarioSchema.extend({ todo_el_dia: z.boolean(), link: z.string() })),
  reuniones: z.array(z.object({ titulo: z.string(), dia: z.string(), hora: z.string(), cliente: z.string(), meet: z.boolean(), link: z.string() })),
  calendario: z.array(ItemCalendarioSchema),
  error: z.string().optional(),
})
export type Agenda = z.infer<typeof AgendaSchema>

/* ---------- Avisos ---------- */

export const BandejaSchema = z.enum(['principal', 'otras', 'chat', 'tarde', 'papelera'])
export type Bandeja = z.infer<typeof BandejaSchema>

export const AvisoSchema = z.object({
  id: IdSchema,
  tipo: z.string(),
  titulo: z.string(),
  cuerpo: z.string(),
  // Ruta del front o, en avisos antiguos, del PHP (task.php?id=…): rutaDesdeLegado.
  url: z.string(),
  tarea: z.string(),
  actor: z.string(),
  actor_foto: z.string().nullable(),
  leido: z.boolean(),
  snooze_until: z.string().nullable(),
  created_at: z.string(),
  tarea_id: IdSchema.nullable(),
  tarea_estado: EstadoSchema.nullable(),
})
export type Aviso = z.infer<typeof AvisoSchema>

const Contador = z.object({ total: z.number().int(), no_leidas: z.number().int() })
export const AvisosPagina = paginaSchema(AvisoSchema).extend({
  contadores: z.object({ principal: Contador, otras: Contador, chat: Contador, tarde: Contador, papelera: Contador }),
})
export type AvisosPagina = z.infer<typeof AvisosPagina>

export const SondeoSchema = z.object({
  no_leidas: z.number().int(),
  ultimo_id: z.number().int(),
  nuevos: z.array(z.object({ id: IdSchema, tipo: z.string(), titulo: z.string(), cuerpo: z.string(), url: z.string(), tarea: z.string(), actor: z.string() })),
})
export type Sondeo = z.infer<typeof SondeoSchema>

export const AccionAvisosRespuesta = z.object({ cambiadas: z.number().int(), no_leidas: z.number().int().optional(), hasta: z.string().nullable().optional() })

/* ---------- Búsqueda ---------- */

export const BusquedaSchema = z.object({
  q: z.string(),
  n: z.number().int(),
  grupos: z.array(z.object({ g: z.string(), r: z.array(z.object({ t: z.string(), s: z.string(), u: z.string(), i: z.string() })) })),
})
export type Busqueda = z.infer<typeof BusquedaSchema>

/* ---------- Papelera ---------- */

export const ElementoPapeleraSchema = z.object({
  id: IdSchema,
  tipo: z.string(),
  tipo_label: z.string(),
  titulo: z.string(),
  ref_id: IdSchema,
  autor: z.string(),
  mio: z.boolean(),
  created_at: z.string(),
  caduca: z.string(),
})
export type ElementoPapelera = z.infer<typeof ElementoPapeleraSchema>
export const PapeleraPagina = paginaSchema(ElementoPapeleraSchema).extend({
  dias: z.number().int(),
  permisos: z.object({ restaurar: z.boolean(), purgar: z.boolean() }),
})
export const RestaurarPapeleraRespuesta = z.object({ id: IdSchema, tipo: z.string(), msg: z.string(), url: z.string() })
export const VaciarRespuesta = z.object({ borrados: z.number().int() })

/* ---------- Actas ---------- */

export const ActaSchema = z.object({
  id: IdSchema,
  titulo: z.string(),
  extracto: z.string(),
  autor: PersonaSchema.nullable(),
  fijada: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})
export type Acta = z.infer<typeof ActaSchema>
export const ActaCompletaSchema = ActaSchema.extend({ contenido: z.string(), puede_editar: z.boolean() })
export type ActaCompleta = z.infer<typeof ActaCompletaSchema>
export const ActasPagina = paginaSchema(ActaSchema).extend({
  autores: z.array(z.object({ persona: PersonaSchema.nullable(), n: z.number().int() })),
  puede_editar: z.boolean(),
})
export type ActasPagina = z.infer<typeof ActasPagina>
export const ActaRespuesta = z.object({ acta: ActaCompletaSchema })
export const PapeleraIdRespuesta = z.object({ papelera_id: IdSchema })
export const Vacio = z.object({})
