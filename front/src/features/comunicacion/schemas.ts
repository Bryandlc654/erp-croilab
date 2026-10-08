import { z } from 'zod'
import { IdSchema, PersonaSchema } from '../../shared/schemas'

/* Contrato de la API de Comunicación (docs/migracion/api/comunicacion.md). */

export const VacioRespuesta = z.object({})
export const MsgRespuesta = z.object({ msg: z.string() })

/* ---------- Chat ---------- */

export const PresenciaSchema = z.object({ estado: z.enum(['online', 'idle', 'offline']), texto: z.string() })
export type PresenciaEstado = z.infer<typeof PresenciaSchema>
export const MapaPresencia = z.record(z.string(), PresenciaSchema)

export const SalaSchema = z.object({
  id: IdSchema,
  tipo: z.enum(['dm', 'grupo']),
  nombre: z.string(),
  miembros: z.array(PersonaSchema),
  otro_id: IdSchema.nullable(),
  creado_por: IdSchema.nullable(),
  ultimo: z
    .object({ id: IdSchema, texto: z.string(), autor_id: IdSchema, autor: z.string(), creado: z.string(), borrado: z.boolean(), adjunto: z.boolean() })
    .nullable(),
  no_leidos: z.number().int(),
})
export type Sala = z.infer<typeof SalaSchema>

export const SalasRespuesta = z.object({
  salas: z.array(SalaSchema),
  personas: z.array(PersonaSchema),
  presencia: MapaPresencia,
  yo: IdSchema,
  puede_crear_grupo: z.boolean(),
  puede_gestionar: z.boolean(),
})
export type SalasDatos = z.infer<typeof SalasRespuesta>
export const SalaRespuesta = z.object({ sala: SalaSchema })
export const SalaOpcionalRespuesta = z.object({ sala: SalaSchema.nullable() })

export const AdjuntoChat = z.object({ indice: z.number().int(), nombre: z.string(), imagen: z.boolean(), mime: z.string(), tamano: z.number().nullable(), url: z.string() })
export const ReaccionChat = z.object({ emoji: z.string(), total: z.number().int(), mio: z.boolean(), personas: z.array(z.string()) })

export const MensajeSchema = z.object({
  id: IdSchema,
  sala_id: IdSchema,
  autor_id: IdSchema,
  autor: z.string(),
  foto: z.string().nullable(),
  texto: z.string(),
  creado: z.string(),
  editado: z.boolean(),
  borrado: z.boolean(),
  responde_a: z.object({ id: IdSchema, autor: z.string(), extracto: z.string() }).nullable(),
  adjuntos: z.array(AdjuntoChat),
  reacciones: z.array(ReaccionChat),
})
export type Mensaje = z.infer<typeof MensajeSchema>
export const MensajeRespuesta = z.object({ mensaje: MensajeSchema })

export const HistorialRespuesta = z.object({ mensajes: z.array(MensajeSchema), hay_mas: z.boolean(), leido_hasta: z.number().int(), cursor: z.string() })
export type Historial = z.infer<typeof HistorialRespuesta>

export const NovedadesRespuesta = z.object({
  mensajes: z.array(MensajeSchema),
  cambios: z.array(MensajeSchema),
  escribiendo: z.array(PersonaSchema),
  leido_hasta: z.number().int(),
  cursor: z.string(),
  no_leidos: z.record(z.string(), z.number().int()),
  presencia: MapaPresencia,
})
export type Novedades = z.infer<typeof NovedadesRespuesta>

export const AvisoChat = z.object({
  id: IdSchema,
  sala_id: IdSchema,
  autor_id: IdSchema,
  autor: z.string(),
  foto: z.string().nullable(),
  texto: z.string(),
  grupo: z.boolean(),
  sala: z.string(),
})
export type AvisoChatT = z.infer<typeof AvisoChat>
export const AvisosRespuesta = z.object({ mensajes: z.array(AvisoChat), max: z.number().int(), no_leidos: z.number().int(), silenciado: z.boolean() })
export type AvisosChat = z.infer<typeof AvisosRespuesta>

/* ---------- Soporte ---------- */

export const ESTADOS_TICKET_ORDEN = ['abierto', 'en_curso', 'esperando', 'resuelto', 'cerrado'] as const
export type EstadoTicket = (typeof ESTADOS_TICKET_ORDEN)[number]

export const TicketSchema = z.object({
  id: IdSchema,
  asunto: z.string(),
  cuerpo: z.string(),
  client_id: IdSchema.nullable(),
  cliente: z.string().nullable(),
  prioridad: z.number().int(),
  estado: z.enum(ESTADOS_TICKET_ORDEN),
  asignado: PersonaSchema.nullable(),
  creador: PersonaSchema.nullable(),
  desde_portal: z.boolean(),
  creado: z.string(),
  actualizado: z.string(),
  respuestas: z.number().int(),
})
export type Ticket = z.infer<typeof TicketSchema>

export const TicketsRespuesta = z.object({
  items: z.array(TicketSchema),
  contadores: z.object({ abierto: z.number().int(), en_curso: z.number().int(), esperando: z.number().int(), resuelto: z.number().int(), total: z.number().int() }),
  cliente: z.object({ id: IdSchema, nombre: z.string() }).nullable(),
  puede_editar: z.boolean(),
  puede_responder: z.boolean(),
})
export type TicketsDatos = z.infer<typeof TicketsRespuesta>

export const RespuestaTicket = z.object({ id: IdSchema, autor: PersonaSchema.nullable(), cuerpo: z.string(), creado: z.string() })
export type RespuestaTicketT = z.infer<typeof RespuestaTicket>
export const TicketDetalleRespuesta = z.object({ ticket: TicketSchema, respuestas: z.array(RespuestaTicket), puede_editar: z.boolean(), puede_responder: z.boolean() })
export type TicketDetalle = z.infer<typeof TicketDetalleRespuesta>
export const TicketRespuesta = z.object({ ticket: TicketSchema })
export const ResponderRespuesta = z.object({ respuesta: RespuestaTicket, ticket: TicketSchema })
export const PapeleraRespuesta = z.object({ papelera_id: z.number().int() })
export const RestaurarRespuesta = z.object({ id: z.number().int() })
export const ClientesSoporteRespuesta = z.object({ items: z.array(z.object({ id: IdSchema, nombre: z.string(), activo: z.boolean() })) })
export type ClienteOpcion = z.infer<typeof ClientesSoporteRespuesta>['items'][number]

/* ---------- Google: calendario y reuniones ---------- */

export const EstadoGoogle = z.object({ configurado: z.boolean(), conectado: z.boolean(), revocado: z.boolean(), email: z.string() })
export type EstadoGoogleT = z.infer<typeof EstadoGoogle>

export const EventoSchema = z.object({
  id: z.string(),
  titulo: z.string(),
  dia: z.string(),
  dia_fin: z.string(),
  hora: z.string(),
  hora_fin: z.string(),
  todo_el_dia: z.boolean(),
  editable: z.boolean(),
  invitados: z.array(z.string()),
  ubicacion: z.string(),
  descripcion: z.string(),
  meet: z.boolean(),
  meet_url: z.string(),
  recurrente: z.boolean(),
  serie_id: z.string(),
  link: z.string(),
  docs: z.array(z.object({ titulo: z.string(), url: z.string() })),
  recordar: z.string(),
  erp_meeting: z.string(),
})
export type Evento = z.infer<typeof EventoSchema>

export const EventoCalendario = EventoSchema.extend({
  color: z.string(),
  mio: z.boolean(),
  owner: z.object({ id: IdSchema, username: z.string() }).nullable(),
})
export type EventoCal = z.infer<typeof EventoCalendario>

export const TareaCalendario = z.object({
  id: IdSchema,
  titulo: z.string(),
  estado: z.string(),
  fecha: z.string(),
  client_id: IdSchema.nullable(),
  cliente: z.string(),
  asignados: z.array(PersonaSchema),
})
export type TareaCal = z.infer<typeof TareaCalendario>

export const CalendarioRespuesta = z.object({
  desde: z.string(),
  hasta: z.string(),
  tareas: z.array(TareaCalendario),
  eventos: z.array(EventoCalendario),
  festivos: z.record(z.string(), z.string()),
  google: EstadoGoogle,
  companeros: z.array(z.object({ id: IdSchema, username: z.string(), color: z.string() })),
  puede_configurar: z.boolean(),
  avisos: z.array(z.string()),
})
export type CalendarioDatos = z.infer<typeof CalendarioRespuesta>

export const EventoRespuesta = z.object({ msg: z.string(), evento: EventoSchema.nullable() })
export const CorreosRespuesta = z.object({ items: z.array(z.object({ email: z.string(), nombre: z.string(), tipo: z.enum(['equipo', 'contacto', 'cliente']) })) })
export type CorreoSugerido = z.infer<typeof CorreosRespuesta>['items'][number]

export const ReunionSchema = EventoSchema.extend({
  owner: PersonaSchema.nullable(),
  contacto: z.object({ id: IdSchema, nombre: z.string(), empresa: z.string(), client_id: IdSchema.nullable() }).nullable(),
  cliente: z.object({ id: IdSchema, nombre: z.string() }).nullable(),
  emparejado: z.enum(['manual', 'contacto', 'cliente']).nullable(),
})
export type Reunion = z.infer<typeof ReunionSchema>

export const SolicitudSchema = z.object({
  id: IdSchema,
  client_id: IdSchema,
  cliente: z.string(),
  email: z.string(),
  contact_id: IdSchema.nullable(),
  fecha_deseada: z.string().nullable(),
  franja: z.string(),
  motivo: z.string(),
  creado: z.string(),
})
export type Solicitud = z.infer<typeof SolicitudSchema>

export const ReunionesRespuesta = z.object({
  google: EstadoGoogle,
  cuentas: z.array(z.object({ id: IdSchema, username: z.string() })),
  vista: z.string(),
  proximas: z.array(ReunionSchema),
  pasadas: z.array(ReunionSchema),
  notas: z.array(ReunionSchema),
  solicitudes: z.array(SolicitudSchema),
  meeting_url: z.string(),
  puede_editar: z.boolean(),
  avisos: z.array(z.string()),
})
export type ReunionesDatos = z.infer<typeof ReunionesRespuesta>

export const AgendarRespuesta = z.object({ msg: z.string(), mid: z.number().int().nullable(), evento: EventoSchema.nullable(), google: z.boolean() })
export type AgendarResultado = z.infer<typeof AgendarRespuesta>

export const ContactosRespuesta = z.object({
  grupos: z.array(z.object({ grupo: z.string(), items: z.array(z.object({ id: IdSchema, nombre: z.string(), empresa: z.string(), fase: z.string(), email: z.string() })) })),
})
export type GrupoContactos = z.infer<typeof ContactosRespuesta>['grupos'][number]

export const DestinatarioRespuesta = z.object({
  destinatario: z.object({
    nombre: z.string(),
    email: z.string(),
    whatsapp: z.string(),
    contact_id: IdSchema.nullable(),
    client_id: IdSchema.nullable(),
    meeting_url: z.string(),
    google: z.boolean(),
  }),
})
export type Destinatario = z.infer<typeof DestinatarioRespuesta>['destinatario']

export const UrlRespuesta = z.object({ url: z.string() })
