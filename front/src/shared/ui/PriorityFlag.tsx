import { PRIORIDADES, PRIORIDADES_VIVAS } from '../lib/paletas'

/* Prioridad (.flagp): cuadradito de color + texto. Sin prioridad, «＋» (para
   invitar a ponerla) o «Sin prioridad» si `emptyLabel`. */
export default function PriorityFlag({
  value,
  palette = 'strong',
  showLabel = true,
  emptyLabel,
  className = '',
}: {
  value: number
  palette?: 'strong' | 'vivid'
  showLabel?: boolean
  emptyLabel?: string
  className?: string
}) {
  const lista = palette === 'vivid' ? PRIORIDADES_VIVAS : PRIORIDADES
  const p = lista.find((x) => x.value === value) ?? lista[0]
  if (p.value === 0) {
    return (
      <span className={`inline-flex items-center text-[12.5px] ${emptyLabel ? 'text-muted' : 'text-label'} ${className}`} aria-label="Sin prioridad">
        {emptyLabel ?? '＋'}
      </span>
    )
  }
  return (
    <span className={`inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-ink ${className}`} aria-label={showLabel ? undefined : `Prioridad ${p.label}`}>
      <span className="size-[9px] shrink-0 rounded-[2px]" style={{ backgroundColor: p.color }} aria-hidden="true" />
      {showLabel && p.label}
    </span>
  )
}
