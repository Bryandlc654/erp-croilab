import { eur } from '../../../shared/lib/formato'
import type { Seccion, TipoAcceso } from '../schemas'

/* Textos y colores fijos del área de clientes, en un sitio. */

/* Céntimos → «1.234,56 €»; sin permiso de ver importes, «·····» (eur_vis). */
export function importe(c: number | null | undefined) {
  return c === null || c === undefined ? '·····' : eur(c / 100)
}

export const TIPOS_ACCESO: { value: TipoAcceso; label: string }[] = [
  { value: 'figma', label: 'Figma' },
  { value: 'drive', label: 'Google Drive' },
  { value: 'web', label: 'Sitio web' },
  { value: 'looker', label: 'Looker Studio' },
  { value: 'generic', label: 'Otro' },
]

export const CATEGORIAS_CREDENCIAL: Record<string, string> = {
  web: 'Web',
  correo: 'Correo',
  hosting: 'Hosting',
  database: 'Base de datos',
  api: 'API / Token',
  cms: 'CMS',
  domain: 'Dominio',
  social: 'Redes',
  other: 'Acceso',
}

/* Secciones del portal que decide el tipo de cliente (type-edit.php). */
export const SECCIONES_TIPO: { key: Seccion; titulo: string; texto: string }[] = [
  { key: 'metricas', titulo: 'Métricas', texto: 'Llamadas, WhatsApps, formularios y visitas, mes a mes.' },
  { key: 'progreso', titulo: 'Progreso', texto: 'Las fases del trabajo y en cuál va ahora mismo.' },
  { key: 'informes', titulo: 'Informes', texto: 'Los informes mensuales que le subes.' },
  { key: 'como', titulo: 'Método', texto: 'Cómo trabajáis, con el vídeo de presentación.' },
  { key: 'accesos', titulo: 'Accesos', texto: 'Las claves y enlaces que le has dejado preparados.' },
  { key: 'plan', titulo: 'Plan', texto: 'Qué incluye lo que tiene contratado.' },
]

/* Prioridades de los tickets (1-4), colores vivos de support.php. */
export const PRIORIDADES_TICKET: Record<number, { label: string; color: string }> = {
  1: { label: 'Baja', color: '#94a3b8' },
  2: { label: 'Normal', color: '#3b82f6' },
  3: { label: 'Alta', color: '#f59e0b' },
  4: { label: 'Urgente', color: '#ef4444' },
}

export const OBJETIVOS_GOOGLE = {
  ll: { emoji: '📞', titulo: 'Llamadas', texto: 'Clic en el teléfono' },
  wa: { emoji: '💬', titulo: 'WhatsApp', texto: 'Clic en WhatsApp' },
  fo: { emoji: '📝', titulo: 'Formularios', texto: 'Formulario enviado' },
} as const

const BASE = (import.meta.env.VITE_BASE_PATH ?? '/admin').replace(/\/+$/, '')

/* Enlace de acceso al portal con la marca de una agencia (login.php?m=ID en el
   antiguo). El portal nuevo vivirá en /portal/* (RUTAS.md). */
export function enlaceAcceso(id: number, origen = window.location.origin) {
  return `${origen}${BASE}/portal/login?m=${id}`
}
