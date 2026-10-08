import { ESTADOS_FACTURA, type Tono } from '../../../shared/lib/paletas'
import type { EstadoFactura } from '../schemas'

/* Colores de estado de las facturas (paleta del ERP + «Anulada», nueva). */
export const ESTADO: Record<EstadoFactura, Tono> = {
  borrador: ESTADOS_FACTURA.borrador,
  enviada: ESTADOS_FACTURA.enviada,
  pagada: ESTADOS_FACTURA.pagada,
  vencida: ESTADOS_FACTURA.vencida,
  anulada: { label: 'Anulada', color: '#6b7280' },
}

/* Ingreso / gasto en Contabilidad y carpetas. */
export const COLOR_INGRESO = '#12854a'
export const COLOR_GASTO = '#c0392b'
export const VERDE = '#12a150'
export const ROJO = '#e05a4f'

export const PALETA_PROYECTOS = ['#2f6df6', '#12a150', '#e0a341', '#e05a4f', '#8b5cf6', '#0ea5a5', '#eb5a9a', '#f59e0b', '#14b8a6', '#a855f7']
