/* Colores de datos del ERP antiguo en un solo sitio. Conviven dos versiones:
   la «fuerte» (listas, píldoras, cabeceras) y la «viva» (círculo de estado,
   calendario, detalle). Ver docs/migracion/00-estilo-ui.md §2.B. */

export type EstadoTarea = 'pendiente' | 'en proceso' | 'atemporal' | 'completada'
export type Tono = { label: string; color: string }

/* Estados de tarea, versión fuerte (tablero antiguo, workspace.php). */
export const ESTADOS: Record<EstadoTarea, Tono> = {
  pendiente: { label: 'En espera', color: '#64748b' },
  'en proceso': { label: 'En proceso', color: '#2563eb' },
  atemporal: { label: 'Atemporal', color: '#a16207' },
  completada: { label: 'Completada', color: '#0f7a3d' },
}
export const ORDEN_ESTADOS: EstadoTarea[] = ['pendiente', 'en proceso', 'atemporal', 'completada']

/* Mismos estados, versión viva (estado_circle, calendario, soporte). */
export const ESTADOS_VIVOS: Record<EstadoTarea, Tono> = {
  pendiente: { label: 'En espera', color: '#b0b4bb' },
  'en proceso': { label: 'En proceso', color: '#3b82f6' },
  atemporal: { label: 'Atemporal', color: '#e0a000' },
  completada: { label: 'Completada', color: '#12a150' },
}

export type Prioridad = { value: number; label: string; color: string }

/* Prioridades 0-4, versión fuerte. */
export const PRIORIDADES: Prioridad[] = [
  { value: 0, label: 'Ninguna', color: '#94a3b8' },
  { value: 1, label: 'Baja', color: '#64748b' },
  { value: 2, label: 'Normal', color: '#2563eb' },
  { value: 3, label: 'Alta', color: '#b45309' },
  { value: 4, label: 'Urgente', color: '#b91c1c' },
]

/* Prioridades 0-4, versión viva. */
export const PRIORIDADES_VIVAS: Prioridad[] = [
  { value: 0, label: 'Ninguna', color: '#cfd2d6' },
  { value: 1, label: 'Baja', color: '#94a3b8' },
  { value: 2, label: 'Normal', color: '#3b82f6' },
  { value: 3, label: 'Alta', color: '#f59e0b' },
  { value: 4, label: 'Urgente', color: '#ef4444' },
]

/* Fases del embudo CRM (semilla de pipeline_stages): la BD manda; esto es el
   respaldo para fases sin color guardado. */
export const FASES_CRM: Record<string, string> = {
  'Lead nuevo': '#64748b',
  Onboarding: '#2563eb',
  'Onboarding hecho': '#1d4ed8',
  'Propuesta enviada': '#a16207',
  Negociación: '#c2410c',
  'Contrato firmado': '#0f7a3d',
  'Cerrado ganado': '#047857',
  'Cerrado perdido': '#b91c1c',
  'En pausa': '#64748b',
}
export const FASE_DESCONOCIDA = '#98a2b3'
export const FASE_NUEVA = '#94a3b8'

export const ESTADOS_FACTURA: Record<string, Tono> = {
  borrador: { label: 'Borrador', color: '#9aa0a8' },
  enviada: { label: 'Enviada', color: '#3b82f6' },
  pagada: { label: 'Pagada', color: '#12a150' },
  vencida: { label: 'Vencida', color: '#ef4444' },
}

export const ESTADOS_TICKET: Record<string, Tono> = {
  abierto: { label: 'Abierto', color: '#3b82f6' },
  en_curso: { label: 'En curso', color: '#7b68ee' },
  esperando: { label: 'Esperando', color: '#e0a000' },
  resuelto: { label: 'Resuelto', color: '#12a150' },
  cerrado: { label: 'Cerrado', color: '#9aa0a8' },
}

export const ESTADOS_REUNION: Record<string, Tono> = {
  agendada: { label: 'Agendada', color: '#6b7280' },
  realizada: { label: 'Realizada', color: '#12a150' },
  no_show: { label: 'No se presentó', color: '#c76a12' },
  cancelada: { label: 'Cancelada', color: '#c0343a' },
}

/* Tipos de comentario del CRM: fondo y texto. */
export const TIPOS_COMENTARIO: Record<string, { label: string; bg: string; fg: string }> = {
  normal: { label: 'Nota', bg: '#eef0f3', fg: '#5c616b' },
  llamada: { label: 'Llamada', bg: '#e6f6ee', fg: '#12854a' },
  whatsapp: { label: 'WhatsApp', bg: '#e3f7ed', fg: '#0f7a3d' },
  email: { label: 'Email', bg: '#e8effc', fg: '#2f6df6' },
  reunion: { label: 'Reunión', bg: '#fdf1e3', fg: '#c76a12' },
}

export const PALETA_GRAFICAS = ['#5b8def', '#12a150', '#f0872a', '#e0a000', '#7c9cf5', '#ef4444', '#12854a', '#94a3b8', '#a855f7', '#06b6d4']

/* Colores de los compañeros en el calendario. */
export const COLORES_COMPANEROS = ['#8e44ad', '#e67e22', '#16a085', '#d35400', '#2980b9', '#c0392b', '#0f9d58']

export type Presencia = 'online' | 'idle' | 'offline'
export const PRESENCIA: Record<Presencia, Tono> = {
  online: { label: 'En línea', color: '#12a150' },
  idle: { label: 'Ausente', color: '#f0872a' },
  offline: { label: 'Desconectado', color: '#c0c4cb' },
}
