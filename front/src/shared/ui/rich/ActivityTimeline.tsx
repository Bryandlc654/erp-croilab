import type { ReactNode } from 'react'
import { horaRelativa } from '../../lib/formato'

export type ItemActividad = {
  id?: string | number
  /* Fecha ('AAAA-MM-DD HH:MM:SS' o ISO). */
  at: string
  text: ReactNode
  /* Quién lo hizo (en negrita delante). */
  actor?: string | null
  /* Color del punto (por defecto gris). */
  color?: string
}

/* Historial de actividad (.pf-acthist del CRM / líneas .sys de la tarea):
   filas con punto, texto y fecha a la derecha. */
export default function ActivityTimeline({
  items,
  formatDate = (s) => horaRelativa(s),
  empty = null,
  className = '',
}: {
  items: ItemActividad[]
  formatDate?: (at: string) => string
  empty?: ReactNode
  className?: string
}) {
  if (!items.length) return <>{empty}</>
  return (
    <ol className={className}>
      {items.map((it, k) => (
        <li key={it.id ?? k} className="flex items-baseline gap-2.5 border-t border-line2 py-[7px] text-[12.5px] text-ink first:border-t-0">
          <span className="size-1.5 shrink-0 -translate-y-px self-center rounded-full bg-[#c4c8ce] dark:bg-line-strong" style={it.color ? { backgroundColor: it.color } : undefined} aria-hidden="true" />
          <span className="min-w-0 flex-1">
            {it.actor && <b className="font-semibold text-ink-strong">{it.actor} </b>}
            {it.text}
          </span>
          <time dateTime={it.at} title={it.at} className="max-w-[140px] shrink-0 truncate text-[11.5px] text-muted">
            {formatDate(it.at)}
          </time>
        </li>
      ))}
    </ol>
  )
}
