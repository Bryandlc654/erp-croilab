import { PRESENCIA, type Presencia } from '../lib/paletas'

/* Punto de presencia (en línea / ausente / desconectado) con el anillo del
   color de fondo, para ponerlo sobre la esquina de un avatar. */
export default function PresenceDot({ state, size = 12, className = '', ring = 'var(--c-card)' }: { state: Presencia; size?: number; className?: string; ring?: string }) {
  return (
    <span
      role="img"
      aria-label={PRESENCIA[state].label}
      className={`inline-block shrink-0 rounded-full ${className}`}
      style={{ width: size, height: size, backgroundColor: PRESENCIA[state].color, boxShadow: size >= 10 ? `0 0 0 2.5px ${ring}` : undefined }}
    />
  )
}
