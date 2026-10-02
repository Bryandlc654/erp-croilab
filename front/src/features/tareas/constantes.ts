import type { Estado } from './schemas'

/* Mismos textos y colores que el tablero antiguo (workspace.php). */
export const ESTADOS: Record<Estado, { label: string; color: string }> = {
  pendiente: { label: 'En espera', color: '#64748b' },
  'en proceso': { label: 'En proceso', color: '#2563eb' },
  atemporal: { label: 'Atemporal', color: '#a16207' },
  completada: { label: 'Completada', color: '#0f7a3d' },
}
export const ORDEN_ESTADOS: Estado[] = ['pendiente', 'en proceso', 'atemporal', 'completada']

export const PRIORIDADES = [
  { value: 0, label: 'Ninguna', color: '#94a3b8' },
  { value: 1, label: 'Baja', color: '#64748b' },
  { value: 2, label: 'Normal', color: '#2563eb' },
  { value: 3, label: 'Alta', color: '#b45309' },
  { value: 4, label: 'Urgente', color: '#b91c1c' },
]
