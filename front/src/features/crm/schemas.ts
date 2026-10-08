import { z } from 'zod'

/* Contrato de la API del CRM (docs/migracion/api/crm.md). Toda respuesta se
   valida aquí antes de llegar a una pantalla. */

const Id = z.number().int()
const Fecha = z.string()
const Importe = z.number().nullable()

export const EtiquetaSchema = z.object({ id: Id, nombre: z.string(), color: z.string() })
export type Etiqueta = z.infer<typeof EtiquetaSchema>

export const FaseSchema = z.object({
  id: Id,
  nombre: z.string(),
  slug: z.string(),
  orden: z.number().int(),
  probabilidad: z.number().int(),
  tipo: z.enum(['abierta', 'ganada', 'perdida', 'pausa']),
  color: z.string(),
  estructural: z.boolean(),
})
export type Fase = z.infer<typeof FaseSchema>

const Par = z.object({ value: z.string(), label: z.string() })

export const CatalogosSchema = z.object({
  fases: z.array(FaseSchema),
  origenes: z.array(z.string()),
  servicios: z.array(z.string()),
  sectores: z.array(z.string()),
  etiquetas: z.array(EtiquetaSchema),
  tipos_comentario: z.array(Par),
  motivos_perdida: z.array(Par.extend({ meses: z.number().int().nullable() })),
  estados_propuesta: z.array(Par),
  canales: z.array(Par.extend({ color: z.string() })),
  estados_reunion: z.array(Par),
})
export type Catalogos = z.infer<typeof CatalogosSchema>

export const ContactoSchema = z.object({
  id: Id,
  nombre: z.string(),
  empresa: z.string(),
  sector: z.string(),
  email: z.string(),
  telefono: z.string(),
  whatsapp: z.string(),
  linkedin: z.string(),
  web: z.string(),
  origen_lead: z.string(),
  servicios: z.array(z.string()),
  valor: Importe,
  fase: z.string(),
  proxima_accion: z.string(),
  fecha_prox: Fecha.nullable(),
  accion_vencida: z.boolean(),
  propietario_id: Id.nullable(),
  ultima_actualizacion: z.string(),
  fecha_creacion: z.string(),
  fecha_ultimo_contacto: Fecha.nullable(),
  client_id: Id.nullable(),
  etiquetas: z.array(EtiquetaSchema),
})
export type Contacto = z.infer<typeof ContactoSchema>

export const NegocioCoincidenteSchema = z.object({ id: Id, nombre: z.string(), valor: Importe, fase: z.string(), contact_id: Id, contacto: z.string() })

export const ContactosPagina = z.object({
  items: z.array(ContactoSchema),
  total: z.number().int(),
  total_sin_filtros: z.number().int(),
  n_filtros: z.number().int(),
  negocios_coincidentes: z.array(NegocioCoincidenteSchema),
  limit: z.number().int(),
  offset: z.number().int(),
})
export type PaginaContactos = z.infer<typeof ContactosPagina>

export const ContactoRespuesta = z.object({ contacto: ContactoSchema })

const Facturacion = z.object({
  razon_social: z.string(),
  cif: z.string(),
  direccion: z.string(),
  cp: z.string(),
  ciudad: z.string(),
  provincia: z.string(),
  pais: z.string(),
  email_facturacion: z.string(),
  iban: z.string(),
})
export type DatosFacturacion = z.infer<typeof Facturacion>

export const ComentarioSchema = z.object({
  id: Id,
  autor_id: Id.nullable(),
  autor: z.string(),
  tipo: z.string(),
  contenido: z.string(),
  fecha: z.string(),
  puede_borrar: z.boolean(),
})
export type ComentarioCrm = z.infer<typeof ComentarioSchema>

export const PropuestaSchema = z.object({ id: Id, nombre: z.string(), importe: Importe, estado: z.string(), fecha_envio: Fecha.nullable(), url_archivo: z.string() })
export type Propuesta = z.infer<typeof PropuestaSchema>

export const AdjuntoSchema = z.object({ id: Id, nombre: z.string(), url: z.string(), mime: z.string(), fecha: z.string() })
export type AdjuntoCrm = z.infer<typeof AdjuntoSchema>

export const ReunionSchema = z.object({
  id: Id,
  fecha: Fecha.nullable(),
  hora: z.string(),
  titulo: z.string(),
  estado: z.string(),
  notas: z.string(),
  docs: z.array(z.object({ title: z.string(), url: z.string() })),
})
export type Reunion = z.infer<typeof ReunionSchema>

export const FichaSchema = z.object({
  contacto: ContactoSchema,
  facturacion: Facturacion,
  comentarios: z.array(ComentarioSchema),
  propuestas: z.array(PropuestaSchema),
  adjuntos: z.array(AdjuntoSchema),
  listas: z.array(z.object({ id: Id, nombre: z.string(), tipo: z.string() })),
  reuniones: z.array(ReunionSchema),
  actividad: z.array(z.object({ id: Id, tipo: z.string(), descripcion: z.string(), fecha: z.string() })),
  negocios: z.array(z.object({ id: Id, nombre: z.string(), valor: Importe, fase: z.string(), archivado: z.boolean() })),
  cliente: z.object({ id: Id, name: z.string() }).nullable(),
})
export type Ficha = z.infer<typeof FichaSchema>

export const NegocioSchema = z.object({
  id: Id,
  contact_id: Id,
  contacto: z.object({ nombre: z.string(), empresa: z.string(), sector: z.string() }),
  nombre: z.string(),
  valor: Importe,
  servicio: z.string(),
  fase: z.string(),
  tipo_fase: z.string(),
  probabilidad: z.number().int(),
  fecha_cierre_prevista: Fecha.nullable(),
  fecha_entrada_fase: Fecha.nullable(),
  dias_en_fase: z.number().int(),
  fecha_cierre_real: Fecha.nullable(),
  motivo_perdida: z.string().nullable(),
  motivo_perdida_txt: z.string(),
  fecha_reactivacion: Fecha.nullable(),
  propietario_id: Id.nullable(),
  archivado: z.boolean(),
  client_id: Id.nullable(),
  invoice_id: Id.nullable(),
  etiquetas: z.array(EtiquetaSchema),
})
export type Negocio = z.infer<typeof NegocioSchema>

export const MetricasSchema = z.object({
  abiertos: z.number(),
  valor_pipeline: z.number().nullable(),
  ganado_mes: z.number().nullable(),
  n_ganado_mes: z.number(),
  conversion: z.number(),
  ticket_medio: z.number().nullable(),
  n_ganados: z.number(),
  n_perdidos: z.number(),
})

export const NegociosRespuesta = z.object({ items: z.array(NegocioSchema), metricas: MetricasSchema.nullable() })
export const NegocioRespuesta = z.object({ negocio: NegocioSchema })

export const PapeleraRespuesta = z.object({ papelera_id: Id })
export const RestauradoRespuesta = z.object({ tipo: z.string(), id: Id })
export const LoteRespuesta = z.object({ n: z.number().int(), list_id: Id.optional(), papelera_ids: z.array(Id).optional() })
export const CsvRespuesta = z.object({ nombre: z.string(), csv: z.string() })
export const VacioRespuesta = z.object({ ok: z.literal(true) })
export const ConversionRespuesta = z.object({
  ya: z.boolean(),
  cliente_id: Id,
  usuario: z.string().nullable(),
  password: z.string().nullable(),
  msg: z.string(),
})
export type Conversion = z.infer<typeof ConversionRespuesta>

export const FacturacionRespuesta = z.object({ facturacion: Facturacion })
export const PropuestasRespuesta = z.object({ propuestas: z.array(PropuestaSchema) })
export const AdjuntosRespuesta = z.object({ adjuntos: z.array(AdjuntoSchema) })
export const FasesRespuesta = z.object({ fases: z.array(FaseSchema) })
export const FaseBorradaRespuesta = z.object({ fases: z.array(FaseSchema), destino: z.string() })
export const EtiquetasRespuesta = z.object({ etiquetas: z.array(EtiquetaSchema) })
export const EtiquetaCreadaRespuesta = z.object({ etiqueta: EtiquetaSchema, etiquetas: z.array(EtiquetaSchema) })

/* Filtros de Contactos: lo que acepta la API (y guardan vistas y listas). */
export const FiltrosSchema = z.object({
  q: z.string().optional(),
  sector: z.string().optional(),
  origen: z.string().optional(),
  fase: z.string().optional(),
  servicio: z.string().optional(),
  prop: z.union([z.number(), z.string()]).optional(),
  tag: z.number().optional(),
  vmin: z.string().optional(),
  vmax: z.string().optional(),
  fdesde: z.string().optional(),
  fhasta: z.string().optional(),
  quick: z.string().optional(),
  sort: z.string().optional(),
  dir: z.string().optional(),
})
export type FiltrosGuardados = z.infer<typeof FiltrosSchema>

export const VistaSchema = z.object({ id: Id, nombre: z.string(), filtros: FiltrosSchema, global: z.boolean(), puede_borrar: z.boolean() })
export type Vista = z.infer<typeof VistaSchema>
export const VistasRespuesta = z.object({ vistas: z.array(VistaSchema) })

/* Listas */
export const ListaSchema = z.object({
  id: Id,
  nombre: z.string(),
  descripcion: z.string(),
  tipo: z.enum(['activa', 'estatica']),
  condiciones: FiltrosSchema,
  fecha_creacion: z.string(),
  fecha_congelado: z.string().nullable(),
  n: z.number().int(),
})
export type Lista = z.infer<typeof ListaSchema>
export const ListasRespuesta = z.object({ listas: z.array(ListaSchema) })
export const ListaRespuesta = z.object({ lista: ListaSchema, miembros: z.array(ContactoSchema.extend({ forzado: z.boolean() })) })
export type ListaDetalle = z.infer<typeof ListaRespuesta>

/* Dashboard */
const Serie = z.object({ label: z.string(), data: z.array(z.number()), color: z.union([z.string(), z.array(z.string())]).nullable().optional() })
export const DatosSchema = z.object({ labels: z.array(z.string()), series: z.array(Serie) })
export const DashboardSchema = z.object({
  rango: z.object({ desde: z.string().nullable(), hasta: z.string().nullable() }),
  kpis: z.object({
    contactos: z.number(),
    hay_rango: z.boolean(),
    abiertos: z.number(),
    valor_pipeline: z.number().nullable(),
    ganado: z.number().nullable(),
    n_ganados: z.number(),
    conversion: z.number(),
    n_perdidos: z.number(),
    ticket_medio: z.number().nullable(),
    cierres_previstos: z.number(),
  }),
  meses: z.array(z.string()),
  series: z.record(z.string(), DatosSchema),
})
export type Dashboard = z.infer<typeof DashboardSchema>

/* Seguimientos */
export const SeguimientoSchema = z.object({
  id: Id,
  contact_id: Id,
  deal_id: Id.nullable(),
  canal: z.string(),
  descripcion: z.string(),
  fecha_prevista: z.string(),
  estado: z.string(),
  secuencia: z.string(),
  vencida: z.boolean(),
  contacto: z.object({ nombre: z.string(), empresa: z.string(), sector: z.string(), telefono: z.string(), whatsapp: z.string(), email: z.string() }),
})
export type Seguimiento = z.infer<typeof SeguimientoSchema>

export const BandejaSchema = z.object({
  hoy: z.string(),
  grupos: z.array(z.object({ canal: z.string(), titulo: z.string(), color: z.string(), items: z.array(SeguimientoSchema) })),
  pendientes_hoy: z.number().int(),
  hechos_hoy: z.number().int(),
  proximos: z.array(SeguimientoSchema),
  ultimo_resumen: z.object({ fecha: z.string(), n_acciones: z.number().int(), enviado: z.number().int() }).nullable(),
  cron: z.object({
    estado: z.enum(['funcionando', 'parado', 'sin_configurar']),
    ultima: z.string().nullable(),
    tareas: z.array(z.object({ tarea: z.string(), ok: z.boolean(), detalle: z.string(), fecha: z.string() })),
    linea: z.string(),
  }),
})
export type Bandeja = z.infer<typeof BandejaSchema>
export const GenerarRespuesta = BandejaSchema.extend({ creados: z.number().int() })
export const ResumenEjecutadoRespuesta = BandejaSchema.extend({
  resultado: z.object({ sent: z.boolean(), acciones: z.number().int(), reason: z.string(), correos: z.number().int() }),
})

export const ResumenSchema = z.object({
  fecha: z.string(),
  dia: z.string(),
  total: z.number().int(),
  grupos: z.array(z.object({ canal: z.string(), titulo: z.string(), color: z.string(), items: z.array(z.object({ nombre: z.string(), dato: z.string(), sub: z.string(), descripcion: z.string() })) })),
  agencia: z.string(),
})
export type Resumen = z.infer<typeof ResumenSchema>

/* Importación CSV */
export const ImportacionSchema = z.object({
  cabecera: z.array(z.string()),
  mapeo: z.array(z.string()),
  separador: z.string(),
  total_filas: z.number().int(),
  validas: z.number().int(),
  a_importar: z.number().int(),
  omitidas: z.array(z.object({ fila: z.number().int(), motivo: z.string() })),
  n_omitidas: z.number().int(),
  duplicados: z.array(z.object({ fila: z.number().int(), nombre: z.string(), motivo: z.string() })),
  n_duplicados: z.number().int(),
  avisos: z.array(z.object({ fila: z.number().int(), msg: z.string() })),
  n_avisos: z.number().int(),
  muestra: z.array(
    z.object({
      fila: z.number().int(),
      nombre: z.string(),
      empresa: z.string(),
      email: z.string(),
      telefono: z.string(),
      fase: z.string(),
      valor: z.number().nullable(),
      servicios: z.string(),
      duplicado: z.boolean(),
    }),
  ),
  insertados: z.number().int(),
})
export type Importacion = z.infer<typeof ImportacionSchema>
