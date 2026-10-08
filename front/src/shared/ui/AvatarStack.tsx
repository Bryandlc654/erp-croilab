import { UserRound } from 'lucide-react'
import Avatar from './Avatar'

export type PersonaAvatar = { id: number | string; username: string; foto?: string | null }

// Solape del ERP según el tamaño (.asg-stack, .mini-av, .pav, calendario).
const SOLAPE: Record<number, number> = { 27: 11, 24: 9, 22: 7, 16: 6 }

/* Pila de avatares con «+N». Vacía: círculo discontinuo con «＋» (o el icono). */
export default function AvatarStack({
  people,
  max = 3,
  size = 27,
  overlap,
  emptyLabel,
  className = '',
}: {
  people: PersonaAvatar[]
  max?: number
  size?: number
  overlap?: number
  /* Texto junto al círculo vacío («Asignar»). */
  emptyLabel?: string
  className?: string
}) {
  const solape = overlap ?? SOLAPE[size] ?? Math.round(size * 0.38)
  if (people.length === 0) {
    return (
      <span className={`inline-flex items-center gap-1.5 text-[12.5px] text-muted ${className}`}>
        <span
          className="flex shrink-0 items-center justify-center rounded-full border-[1.5px] border-dashed border-[#c4c8ce] text-label dark:border-line-strong"
          style={{ width: size, height: size }}
          aria-hidden="true"
        >
          {emptyLabel ? <span className="text-[12px] leading-none">＋</span> : <UserRound className="size-3.5" />}
        </span>
        {emptyLabel}
      </span>
    )
  }
  const vistos = people.slice(0, max)
  const resto = people.length - vistos.length
  const borde = size >= 22 ? 2 : 1.5
  return (
    <span className={`inline-flex items-center ${className}`} aria-label={people.map((p) => p.username).join(', ')} role="img">
      {vistos.map((p, i) => (
        <Avatar
          key={p.id}
          nombre={p.username}
          foto={p.foto}
          size={size}
          className="ring-card"
          // El anillo del color de la tarjeta «recorta» cada avatar sobre el anterior.
          style={{ marginLeft: i === 0 ? 0 : -solape, boxShadow: `0 0 0 ${borde}px var(--c-card)` }}
        />
      ))}
      {resto > 0 && (
        <span
          className="relative inline-flex shrink-0 items-center justify-center rounded-full bg-[#c8ccd2] text-[9.5px] font-bold text-[#3c4149] dark:bg-line-strong dark:text-ink"
          style={{ width: size, height: size, marginLeft: -solape, boxShadow: `0 0 0 ${borde}px var(--c-card)` }}
        >
          +{resto}
        </span>
      )}
    </span>
  )
}
