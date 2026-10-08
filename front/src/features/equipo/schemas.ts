import { z } from 'zod'
import { IdSchema } from '../../shared/schemas'

/* Contrato de la API del módulo Equipo y ajustes (docs/migracion/api/equipo.md). */

export const VacioSchema = z.object({})
export const MsgSchema = z.object({ msg: z.string() })

/* ---------- Miembros ---------- */

export const MiembroSchema = z.object({
  id: IdSchema,
  username: z.string(),
  email: z.string().nullable(),
  role: z.string(),
  role_nombre: z.string(),
  role_descripcion: z.string(),
  es_admin_total: z.boolean(),
  activo: z.boolean(),
  // YYYY-MM de alta.
  desde: z.string().nullable(),
  cumple: z.string().nullable(),
  cargo: z.string(),
  foto: z.string().nullable(),
  yo: z.boolean(),
})
export type Miembro = z.infer<typeof MiembroSchema>

export const FichaMiembroSchema = MiembroSchema.extend({
  es_autonomo: z.boolean(),
  tarifa_hora: z.string(),
  iva_pct: z.string(),
  irpf_pct: z.string(),
  enlace_password: z.object({ caduca: z.string() }).nullable(),
})
export type FichaMiembro = z.infer<typeof FichaMiembroSchema>

export const MiembrosRespuesta = z.object({
  items: z.array(MiembroSchema),
  total: z.number().int(),
  clientes_alta: z.number().int(),
  bajas: z.number().int(),
  roles: z.array(z.object({ clave: z.string(), nombre: z.string(), descripcion: z.string(), total: z.boolean(), asignable: z.boolean() })),
})
export type RolAsignable = z.infer<typeof MiembrosRespuesta>['roles'][number]
export const EnlaceSchema = z.object({ url: z.string(), caduca: z.string(), enviado: z.boolean() })
export type Enlace = z.infer<typeof EnlaceSchema>
export const FichaRespuesta = z.object({ miembro: FichaMiembroSchema })
export const AltaRespuesta = z.object({ miembro: FichaMiembroSchema, enlace: EnlaceSchema.optional() })
export const ActualizarRespuesta = z.object({ miembro: FichaMiembroSchema, recargar: z.boolean() })
export const PasswordRespuesta = z.object({ password: z.string().optional(), enlace: EnlaceSchema.optional() })

/* ---------- Invitaciones y registro ---------- */

export const RolCortoSchema = z.object({ clave: z.string(), nombre: z.string() })
export const InvitacionSchema = z.object({
  id: IdSchema,
  rol: z.string(),
  rol_nombre: z.string(),
  email: z.string().nullable(),
  caduca: z.string(),
  creado_por: z.string().nullable(),
  copiable: z.boolean(),
})
export type Invitacion = z.infer<typeof InvitacionSchema>
export const InvitacionesRespuesta = z.object({ items: z.array(InvitacionSchema), roles: z.array(RolCortoSchema) })
export const InvitacionCreadaRespuesta = z.object({
  invitacion: z.object({ id: IdSchema, url: z.string(), caduca: z.string(), enviado: z.boolean() }),
})
export const UrlRespuesta = z.object({ url: z.string() })

export const MarcaPublicaSchema = z.object({
  nombre: z.string(),
  inicial: z.string(),
  logo: z.string().nullable(),
  color: z.string().nullable(),
})
export const RegistroInfoRespuesta = z.object({
  rol_nombre: z.string(),
  email: z.string().nullable(),
  marca: MarcaPublicaSchema,
  horas: z.number().int(),
})
export type RegistroInfo = z.infer<typeof RegistroInfoRespuesta>
export const RegistroRespuesta = z.object({ username: z.string() })

/* ---------- Perfil y cuenta ---------- */

export const PresenciaSchema = z.object({ estado: z.enum(['online', 'idle', 'offline']), texto: z.string() })
export const TareaPerfilSchema = z.object({
  id: IdSchema,
  titulo: z.string(),
  estado: z.string(),
  due_date: z.string().nullable(),
  cliente: z.string(),
  lista: z.string(),
})
export const PerfilSchema = z.object({
  id: IdSchema,
  username: z.string(),
  email: z.string().nullable(),
  role: z.string(),
  role_nombre: z.string(),
  es_admin_total: z.boolean(),
  activo: z.boolean(),
  foto: z.string().nullable(),
  cargo: z.string(),
  departamento: z.string(),
  telefono: z.string(),
  ubicacion: z.string(),
  web: z.string(),
  skills: z.array(z.string()),
  cumple: z.string().nullable(),
  bio: z.string(),
  presencia: PresenciaSchema,
  yo: z.boolean(),
  puede_editar: z.boolean(),
  tareas: z.object({ items: z.array(TareaPerfilSchema), total: z.number().int() }),
})
export type Perfil = z.infer<typeof PerfilSchema>
export const PerfilRespuesta = z.object({ perfil: PerfilSchema, departamentos: z.array(z.string()) })
export const FotoRespuesta = z.object({ foto: z.string().nullable() })
export const CuentaRespuesta = z.object({
  cuenta: z.object({ username: z.string(), email: z.string().nullable(), password_changed_at: z.string().nullable() }),
})
export const CambioPasswordRespuesta = z.object({ csrf: z.string().nullable() })
export const AvisosRespuesta = z.object({ silenciar: z.array(z.enum(['chat', 'avisos'])) })

/* ---------- Roles ---------- */

export const RolSchema = z.object({
  clave: z.string(),
  nombre: z.string(),
  descripcion: z.string(),
  sistema: z.boolean(),
  permisos: z.array(z.string()),
  total: z.boolean(),
  uso: z.number().int(),
})
export type Rol = z.infer<typeof RolSchema>
export const GrupoPermisosSchema = z.object({
  grupo: z.string(),
  permisos: z.array(z.object({ clave: z.string(), etiqueta: z.string(), descripcion: z.string() })),
})
export type GrupoPermisos = z.infer<typeof GrupoPermisosSchema>
export const RolesRespuesta = z.object({
  roles: z.array(RolSchema),
  catalogo: z.array(GrupoPermisosSchema),
  requisitos: z.record(z.string(), z.array(z.string())),
  config: z.array(z.string()),
  personas: z.number().int(),
})
export type RolesDatos = z.infer<typeof RolesRespuesta>
export const PermisosRespuesta = z.object({ permisos: z.array(z.string()), recargar: z.boolean() })
export const ClaveRespuesta = z.object({ clave: z.string() })

/* ---------- Ajustes ---------- */

export const AgenciaSchema = z.object({
  nombre: z.string(),
  cif: z.string(),
  email: z.string(),
  telefono: z.string(),
  web: z.string(),
  direccion: z.string(),
  logo: z.string(),
  color: z.string(),
})
export type Agencia = z.infer<typeof AgenciaSchema>
export const AgenciaRespuesta = z.object({ agencia: AgenciaSchema, puede_editar: z.boolean() })

export const ContactoSchema = z.object({ email: z.string(), whatsapp: z.string(), meeting_url: z.string() })
export type Contacto = z.infer<typeof ContactoSchema>
export const ContactoRespuesta = z.object({ contacto: ContactoSchema, puede_editar: z.boolean() })

export const VideosRespuesta = z.object({
  video_id: z.string(),
  servicios: z.array(z.object({ nombre: z.string(), video: z.string() })),
  puede_editar: z.boolean(),
})
export type Videos = z.infer<typeof VideosRespuesta>

export const ReglaSchema = z.object({ clave: z.string(), titulo: z.string(), descripcion: z.string(), on: z.boolean() })
export const ReglasRespuesta = z.object({ reglas: z.array(ReglaSchema), puede_editar: z.boolean() })

export const MetricasRespuesta = z.object({
  conectado: z.boolean(),
  revocado: z.boolean(),
  configurado: z.boolean(),
  ultima: z.string().nullable(),
  eventos: z.object({ ll: z.string(), wa: z.string(), fo: z.string() }),
  clientes: z.array(
    z.object({ id: IdSchema, nombre: z.string(), web: z.boolean(), analytics: z.boolean(), conversiones: z.boolean(), sync: z.string().nullable() }),
  ),
  con_web: z.number().int(),
  puede_editar: z.boolean(),
  puede_ajustar: z.boolean(),
})
export type MetricasAjustes = z.infer<typeof MetricasRespuesta>
export const SyncRespuesta = z.object({ msg: z.string() })

/* ---------- Integraciones ---------- */

const CredencialesGoogle = z.object({ client_id: z.string(), secreto_guardado: z.boolean(), secreto_ilegible: z.boolean() })
export const IntegracionesRespuesta = z.object({
  puede_editar: z.boolean(),
  redirect_uri: z.string(),
  api: z.object({ activa: z.boolean() }),
  calendar: CredencialesGoogle.extend({
    configurado: z.boolean(),
    conectado: z.boolean(),
    revocado: z.boolean(),
    email: z.string().nullable(),
    cuentas: z.number().int(),
  }),
  metricas: CredencialesGoogle.extend({
    configurado: z.boolean(),
    conectado: z.boolean(),
    revocado: z.boolean(),
    email: z.string().nullable(),
    ultima_sync: z.string().nullable(),
  }),
  mcp: z.object({ activo: z.boolean() }),
})
export type Integraciones = z.infer<typeof IntegracionesRespuesta>
export const TokenRespuesta = z.object({ token: z.string() })
export const McpRespuesta = z.object({ activo: z.boolean(), url: z.string().optional() })
export const McpUrlRespuesta = z.object({ url: z.string().nullable() })

/* ---------- Bóveda ---------- */

export const ClienteBovedaSchema = z.object({ id: IdSchema, nombre: z.string(), iniciales: z.string().nullable(), activo: z.boolean(), n: z.number().int() })
export type ClienteBoveda = z.infer<typeof ClienteBovedaSchema>
export const BovedaClientesRespuesta = z.object({ items: z.array(ClienteBovedaSchema) })
export const CategoriaSchema = z.enum(['web', 'correo', 'hosting', 'database', 'api', 'cms', 'domain', 'social', 'other'])
export type Categoria = z.infer<typeof CategoriaSchema>
export const CredencialSchema = z.object({
  id: IdSchema,
  client_id: IdSchema,
  titulo: z.string(),
  categoria: CategoriaSchema,
  usuario: z.string(),
  tiene_secreto: z.boolean(),
  url: z.string(),
  nota: z.string(),
  visible_cliente: z.boolean(),
})
export type Credencial = z.infer<typeof CredencialSchema>
export const CredencialesRespuesta = z.object({
  cliente: z.object({ id: IdSchema, nombre: z.string(), iniciales: z.string().nullable() }),
  items: z.array(CredencialSchema),
  puede_editar: z.boolean(),
  categorias: z.array(z.object({ clave: CategoriaSchema, nombre: z.string() })),
})
export const CredencialRespuesta = z.object({ credencial: CredencialSchema })
export const SecretoRespuesta = z.object({ secreto: z.string() })
export const BorrarRespuesta = z.object({ papelera_id: z.number().int().nullable() })
export const ReconfirmarRespuesta = z.object({ hasta: z.string() })
