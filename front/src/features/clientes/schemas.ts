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

/* ---------- Listado (/clientes?actividad=1) ---------- */

export const ClienteFilaSchema = ClienteSchema.extend({
  username: z.string(),
  conversiones: z.boolean(),
  tipo_id: IdSchema.nullable(),
  tipo_nombre: z.string().nullable(),
  partner_id: IdSchema.nullable(),
  tareas_abiertas: z.number().int(),
  tickets_abiertos: z.number().int(),
  // Céntimos; null sin permiso de ver importes.
  pendiente_cobro: z.number().int().nullable(),
})
export type ClienteFila = z.infer<typeof ClienteFilaSchema>
export const ClientesTablaPagina = paginaSchema(ClienteFilaSchema)

/* ---------- Bloques del portal ---------- */

export const FaseSchema = z.object({ t: z.string(), s: z.string(), estado: z.enum(['done', 'now', '']) })
export type Fase = z.infer<typeof FaseSchema>
export const EstadoProyectoSchema = z.object({ nombre: z.string(), etiqueta: z.string(), siguiente: z.string(), fases: z.array(FaseSchema) })
export type EstadoProyecto = z.infer<typeof EstadoProyectoSchema>

export const PlanSchema = z.object({
  resumen: z.string(),
  items: z.array(z.object({ n: z.string(), t: z.string() })),
  detalle: z.array(z.object({ h: z.string(), p: z.string() })),
})
export type Plan = z.infer<typeof PlanSchema>

export const TIPOS_ACCESO = ['figma', 'drive', 'web', 'looker', 'generic'] as const
export type TipoAcceso = (typeof TIPOS_ACCESO)[number]
export const AccesoSchema = z.object({ b: z.string(), s: z.string(), u: z.string(), tipo: z.enum(TIPOS_ACCESO) })
export type Acceso = z.infer<typeof AccesoSchema>

const TareaPortalSchema = z.object({ t: z.string(), d: z.string() })
export const ProgresoMesSchema = z.object({ mes: z.string(), completado: z.array(TareaPortalSchema), pendiente: z.array(TareaPortalSchema) })
export type ProgresoMes = z.infer<typeof ProgresoMesSchema>

/* ---------- Ficha ---------- */

const CabeceraSchema = z.object({
  id: IdSchema,
  name: z.string(),
  username: z.string(),
  iniciales: z.string(),
  saludo: z.string(),
  conversiones: z.boolean(),
  activo: z.boolean(),
  actual: z.string(),
  tipo_id: IdSchema.nullable(),
  tipo_nombre: z.string().nullable(),
  login_email: z.string(),
  partner_id: IdSchema.nullable(),
  contact_id: IdSchema.nullable(),
  // ¿Tiene web o Analytics configurados?
  google: z.boolean(),
})

const Centimos = z.number().int().nullable()

export const FichaSchema = z.object({
  cliente: CabeceraSchema.extend({
    fact: z.object({ nombre: z.string(), nif: z.string(), dir: z.string(), email: z.string(), tel: z.string() }),
    faltan_fiscales: z.number().int(),
  }),
  estado: EstadoProyectoSchema,
  plan: PlanSchema,
  resumen: z.object({
    oportunidades: z.object({ mes: z.string(), valor: z.number().int() }).nullable(),
    tareas_en_curso: z.number().int(),
    soporte_abierto: z.number().int(),
    cobrado: Centimos,
  }),
  listas: z.array(z.object({ id: IdSchema, nombre: z.string(), tipo: z.string(), es_cliente: z.boolean(), cnt: z.number().int(), pend: z.number().int() })),
  facturas: z.object({
    n: z.number().int(),
    cobrado: Centimos,
    pendiente: Centimos,
    ultimas: z.array(z.object({ id: IdSchema, numero: z.string(), fecha: z.string().nullable(), estado: z.string(), total: Centimos })),
  }),
  tickets: z.object({
    abiertos: z.number().int(),
    items: z.array(z.object({ id: IdSchema, asunto: z.string(), estado: z.string(), prioridad: z.number().int(), fecha: z.string().nullable() })),
  }),
  credenciales: z
    .object({
      total: z.number().int(),
      items: z.array(z.object({ id: IdSchema, titulo: z.string(), categoria: z.string(), usuario: z.string(), url: z.string(), tiene_secreto: z.boolean() })),
    })
    .nullable(),
  contacto: z
    .object({ id: IdSchema, nombre: z.string(), empresa: z.string(), email: z.string(), telefono: z.string(), whatsapp: z.string(), origen_lead: z.string() })
    .nullable(),
  reuniones: z.object({
    proximas: z.array(z.object({ id: IdSchema, fecha: z.string(), hora: z.string(), titulo: z.string(), estado: z.string() })),
    solicitudes: z.number().int(),
  }),
})
export type Ficha = z.infer<typeof FichaSchema>

export const SecretoRespuesta = z.object({ secreto: z.string() })

/* ---------- Formulario (datos editables) ---------- */

export const DatosClienteSchema = CabeceraSchema.extend({
  fact_nombre: z.string(),
  fact_nif: z.string(),
  fact_dir: z.string(),
  fact_email: z.string(),
  fact_tel: z.string(),
  estado: EstadoProyectoSchema,
  plan: PlanSchema,
  accesos: z.array(AccesoSchema),
  tareas: z.array(ProgresoMesSchema),
})
export type DatosCliente = z.infer<typeof DatosClienteSchema>
export const DatosRespuesta = z.object({ cliente: DatosClienteSchema })

export const CreadoRespuesta = z.object({ id: IdSchema })
export const DuplicadoRespuesta = z.object({ id: IdSchema, password: z.string() })
export const PasswordRespuesta = z.object({ password: z.string() })
export const BorradoRespuesta = z.object({ papelera_id: IdSchema })
export const RestauradoRespuesta = z.object({ id: IdSchema })
export const VacioRespuesta = z.object({ ok: z.literal(true) })

/* ---------- Tipos de cliente ---------- */

export const SECCIONES = ['metricas', 'progreso', 'informes', 'como', 'accesos', 'plan'] as const
export type Seccion = (typeof SECCIONES)[number]
export const TipoSchema = z.object({
  id: IdSchema,
  nombre: z.string(),
  secciones: z.object({ metricas: z.boolean(), progreso: z.boolean(), informes: z.boolean(), como: z.boolean(), accesos: z.boolean(), plan: z.boolean() }),
  uso: z.number().int(),
})
export type Tipo = z.infer<typeof TipoSchema>
export const TiposRespuesta = z.object({ items: z.array(TipoSchema) })
export const TipoRespuesta = z.object({ tipo: TipoSchema })
export const TipoCreadoRespuesta = z.object({ tipo: TipoSchema, dup: z.boolean() })

/* ---------- Servicios ---------- */

export const ServicioSchema = z.object({ nombre: z.string(), desc: z.string(), video: z.boolean(), uso: z.number().int() })
export type Servicio = z.infer<typeof ServicioSchema>
export const ServiciosRespuesta = z.object({ servicios: z.array(ServicioSchema), abiertos: z.number().int(), total: z.number().int() })
export const ServiciosGuardadosRespuesta = ServiciosRespuesta.extend({ renombrados: z.number().int(), clientes: z.number().int() })

/* ---------- Agencias ---------- */

export const AgenciaSchema = z.object({
  id: IdSchema,
  nombre: z.string(),
  color: z.string(),
  logo_url: z.string(),
  web: z.string(),
  email: z.string(),
  whatsapp: z.string(),
  meeting_url: z.string(),
  telefono: z.string(),
  uso: z.number().int(),
})
export type Agencia = z.infer<typeof AgenciaSchema>
export const AgenciasRespuesta = z.object({
  agencias: z.array(AgenciaSchema),
  clientes: z.array(z.object({ id: IdSchema, name: z.string(), iniciales: z.string(), partner_id: IdSchema.nullable() })),
  casa: z.string(),
})
export const AgenciaRespuesta = z.object({ agencia: AgenciaSchema })

/* ---------- Google ---------- */

export const OBJETIVOS = ['ll', 'wa', 'fo'] as const
export type Objetivo = (typeof OBJETIVOS)[number]
export const MesMetricasSchema = z.object({
  mes: z.string(),
  ll: z.number(),
  wa: z.number(),
  fo: z.number(),
  vi: z.number(),
  ap: z.number(),
  ctr: z.number(),
  total: z.number(),
})
export const GoogleSchema = z.object({
  cliente: z.object({ id: IdSchema, name: z.string(), conversiones: z.boolean() }),
  site: z.string(),
  prop: z.string(),
  eventos: z.object({ ll: z.array(z.string()), wa: z.array(z.string()), fo: z.array(z.string()) }),
  por_defecto: z.object({ ll: z.string(), wa: z.string(), fo: z.string() }),
  conectado: z.boolean(),
  sync_at: z.string().nullable(),
  meses: z.array(MesMetricasSchema),
})
export type Google = z.infer<typeof GoogleSchema>
export const GoogleSyncRespuesta = GoogleSchema.extend({ msg: z.string() })
export const EventosRespuesta = z.object({ eventos: z.array(z.object({ name: z.string(), n: z.number() })) })

/* ---------- Datos avanzados ---------- */

export const BLOQUES_AVANZADOS = ['estado_json', 'plan_json', 'accesos_json', 'tareas_json', 'met_json', 'informes_json', 'servicios_json'] as const
export type BloqueAvanzado = (typeof BLOQUES_AVANZADOS)[number]
export const AvanzadoSchema = z.object({
  id: IdSchema,
  name: z.string(),
  estado_json: z.string(),
  plan_json: z.string(),
  accesos_json: z.string(),
  tareas_json: z.string(),
  met_json: z.string(),
  informes_json: z.string(),
  servicios_json: z.string(),
  looker_url: z.string(),
  fact_tel: z.string(),
  orden: z.number().int(),
})
export type Avanzado = z.infer<typeof AvanzadoSchema>
export const AvanzadoRespuesta = z.object({ datos: AvanzadoSchema })
