import { z } from 'zod'

/* Respuestas de /api/v1/portal/… (docs/migracion/api/portal.md). */

export const Marca = z.object({
  name: z.string(),
  initial: z.string(),
  logo: z.string(),
  color: z.string(),
  web: z.string(),
  propia: z.boolean(),
})
export type Marca = z.infer<typeof Marca>

const ClienteResumen = z.object({ id: z.number(), name: z.string(), saludo: z.string(), iniciales: z.string() })

export const SesionRespuesta = z.object({
  csrf: z.string(),
  cliente: ClienteResumen.nullable(),
  equipo: z.object({ username: z.string() }).nullable(),
  marca: Marca,
  google: z.boolean(),
})
export type SesionPortal = z.infer<typeof SesionRespuesta>

export const LoginRespuesta = z.object({ csrf: z.string(), cliente: ClienteResumen })
export const CsrfRespuesta = z.object({ csrf: z.string() })
export const UrlRespuesta = z.object({ url: z.string() })
export const MsgRespuesta = z.object({ msg: z.string() })
export const UsuarioRespuesta = z.object({ usuario: z.string() })
export const Vacio = z.object({})

export const Fase = z.object({ t: z.string(), s: z.string(), estado: z.string() })
export const Estado = z.object({ nombre: z.string(), etiqueta: z.string(), siguiente: z.string(), fases: z.array(Fase) })
export type Estado = z.infer<typeof Estado>
export const Plan = z.object({
  resumen: z.string(),
  items: z.array(z.object({ n: z.string(), t: z.string() })),
  detalle: z.array(z.object({ h: z.string(), p: z.string() })),
})
export type Plan = z.infer<typeof Plan>
export const Acceso = z.object({ b: z.string(), s: z.string(), u: z.string(), tipo: z.string() })
export type Acceso = z.infer<typeof Acceso>
const TareaTexto = z.object({ t: z.string(), d: z.string() })

export const Metrica = z.object({
  clave: z.string(),
  etiqueta: z.string(),
  mes: z.string(),
  ll: z.number(),
  wa: z.number(),
  fo: z.number(),
  vi: z.number(),
  ap: z.number(),
  ctr: z.number(),
  total: z.number(),
  src: z.record(z.string(), z.number()).or(z.array(z.never()).transform(() => ({}))),
  geo: z.record(z.string(), z.number()).or(z.array(z.never()).transform(() => ({}))),
})
export type Metrica = z.infer<typeof Metrica>

export const MesProgreso = z.object({ clave: z.string().nullable(), etiqueta: z.string(), completado: z.array(TareaTexto), pendiente: z.array(TareaTexto) })
export type MesProgreso = z.infer<typeof MesProgreso>
export const Informe = z.object({ clave: z.string().nullable(), mes: z.string(), etiqueta: z.string(), titulo: z.string(), texto: z.string(), url: z.string() })
export type Informe = z.infer<typeof Informe>

export const Persona = z.object({ nombre: z.string(), color: z.string(), foto: z.string().nullable() })
export type Persona = z.infer<typeof Persona>
export const TareaPortal = z.object({
  id: z.number(),
  titulo: z.string(),
  texto: z.string(),
  estado: z.string(),
  prioridad: z.number(),
  clave: z.string().nullable(),
  mes: z.string(),
  due: z.string().nullable(),
  asignados: z.array(Persona),
})
export type TareaPortal = z.infer<typeof TareaPortal>

export const FacturaFila = z.object({ id: z.number(), numero: z.string(), fecha: z.string(), venc: z.string().nullable(), estado: z.string(), total: z.number() })
export type FacturaFila = z.infer<typeof FacturaFila>
export const Reunion = z.object({ id: z.number(), fecha: z.string().nullable(), hora: z.string(), titulo: z.string(), estado: z.string() })
export type Reunion = z.infer<typeof Reunion>
export const Solicitud = z.object({ id: z.number(), fecha: z.string().nullable(), franja: z.string(), motivo: z.string(), estado: z.string(), creada: z.string().nullable() })
export type Solicitud = z.infer<typeof Solicitud>
export const TicketFila = z.object({ id: z.number(), asunto: z.string(), estado: z.string(), respuestas: z.number(), fecha: z.string().nullable(), actualizado: z.string().nullable() })
export type TicketFila = z.infer<typeof TicketFila>
export const Credencial = z.object({
  id: z.number(),
  titulo: z.string(),
  categoria: z.string(),
  usuario: z.string(),
  url: z.string(),
  nota: z.string(),
  tiene_secreto: z.boolean(),
})
export type Credencial = z.infer<typeof Credencial>

export const Secciones = z.object({
  metricas: z.boolean(),
  progreso: z.boolean(),
  informes: z.boolean(),
  como: z.boolean(),
  accesos: z.boolean(),
  plan: z.boolean(),
})
export type Secciones = z.infer<typeof Secciones>

export const DatosPortal = z.object({
  cliente: ClienteResumen.extend({ username: z.string(), actual: z.string(), actual_etiqueta: z.string() }),
  vista_previa: z.boolean(),
  puede_enviar: z.boolean(),
  secciones: Secciones,
  marca: Marca,
  contacto: z.object({ meeting_url: z.string(), whatsapp: z.string(), email: z.string(), telefono: z.string() }),
  videos: z.object({ general: z.string(), servicios: z.record(z.string(), z.string()).or(z.array(z.never()).transform(() => ({}))) }),
  catalogo: z.array(z.object({ nombre: z.string(), desc: z.string() })),
  servicios: z.array(z.string()).nullable(),
  estado: Estado,
  plan: Plan,
  accesos: z.array(Acceso),
  looker: z.string(),
  metricas: z.array(Metrica),
  progreso: z.array(MesProgreso),
  informes: z.array(Informe),
  tareas: z.array(TareaPortal),
  facturas: z.array(FacturaFila),
  reuniones: z.array(Reunion),
  solicitudes: z.array(Solicitud),
  tickets: z.array(TicketFila),
  credenciales: z.array(Credencial),
})
export type DatosPortal = z.infer<typeof DatosPortal>

export const Ticket = z.object({
  id: z.number(),
  asunto: z.string(),
  cuerpo: z.string(),
  estado: z.string(),
  fecha: z.string().nullable(),
  actualizado: z.string().nullable(),
  respuestas: z.array(z.object({ id: z.number(), cuerpo: z.string(), fecha: z.string().nullable(), autor: Persona.nullable() })),
})
export type Ticket = z.infer<typeof Ticket>
export const TicketRespuesta = z.object({ ticket: Ticket })
export const SolicitudRespuesta = z.object({ solicitud: Solicitud })
export const SecretoRespuesta = z.object({ secreto: z.string() })
export const PdfRespuesta = z.object({ nombre: z.string(), mime: z.string(), base64: z.string() })

/* La hoja de la factura (Finanzas: HojaFactura::datos). */
export const HojaFactura = z.object({
  id: z.number(),
  numero: z.string().nullable(),
  tipo: z.string(),
  estado: z.string(),
  titulo: z.string(),
  fecha: z.string().nullable(),
  fecha_venc: z.string().nullable(),
  cliente: z.object({ nombre: z.string(), nif: z.string(), tel: z.string(), dir: z.string(), email: z.string() }),
  emisor: z.object({ name: z.string(), nif: z.string(), dir: z.string(), email: z.string(), phone: z.string(), iban: z.string(), banco: z.string() }),
  lineas: z.array(z.object({ concepto: z.string(), cantidad: z.string(), precio: z.string(), importe: z.number() })),
  iva_pct: z.union([z.string(), z.number()]),
  irpf_pct: z.union([z.string(), z.number()]),
  totales: z.object({ base: z.number(), iva: z.number(), irpf: z.number(), total: z.number() }),
  pago: z.object({ banco: z.string(), titular: z.string(), forma: z.string(), condiciones: z.string(), iban: z.string() }),
  notas_legales: z.array(z.string()),
  notas: z.string(),
})
export type HojaFactura = z.infer<typeof HojaFactura>
export const FacturaRespuesta = z.object({ factura: HojaFactura })

/* Equipo: editor en vivo. */
export const ContenidoEditable = z.object({
  name: z.string(),
  username: z.string(),
  iniciales: z.string(),
  saludo: z.string(),
  actual: z.string(),
  tipo_id: z.number().nullable(),
  conversiones: z.boolean(),
  looker: z.string(),
  servicios: z.array(z.string()).nullable(),
  estado: Estado,
  plan: Plan,
  accesos: z.array(Acceso),
  tareas: z.array(z.object({ mes: z.string(), completado: z.array(TareaTexto), pendiente: z.array(TareaTexto) })),
  informes: z.array(z.object({ mes: z.string(), titulo: z.string(), texto: z.string(), url: z.string() })),
})
export type ContenidoEditable = z.infer<typeof ContenidoEditable>
export const EditorRespuesta = z.object({
  contenido: ContenidoEditable,
  tipos: z.array(z.object({ id: z.number(), nombre: z.string() })),
  servicios_opciones: z.array(z.string()),
  desde_tareas: z.object({ informes: z.boolean(), progreso: z.boolean() }),
  puede_guardar: z.boolean(),
})
export type EditorRespuesta = z.infer<typeof EditorRespuesta>
export const ClientesEquipoRespuesta = z.object({ items: z.array(z.object({ id: z.number(), name: z.string(), activo: z.boolean() })) })
