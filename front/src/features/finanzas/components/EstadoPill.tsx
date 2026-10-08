import StatusPill from '../../../shared/ui/StatusPill'
import { ESTADO } from '../lib/estados'
import type { EstadoFactura } from '../schemas'

/* Insignia teñida del estado de una factura, con punto. */
export default function EstadoPill({ estado, size = 'md' }: { estado: EstadoFactura; size?: 'sm' | 'md' }) {
  const t = ESTADO[estado]
  return <StatusPill color={t.color} label={t.label} variant="tint" dot size={size} />
}
