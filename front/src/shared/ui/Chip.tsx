import type { ReactNode } from 'react'
import { X } from 'lucide-react'

export type ChipVariant = 'tag' | 'data' | 'pick' | 'act' | 'filter'

const VAR: Record<ChipVariant, string> = {
  tag: 'gap-1.5 rounded-md bg-chip px-2 py-[3px] text-[11px] leading-[1.4] font-semibold text-[#5c616b] dark:text-ink',
  data: 'gap-1.5 rounded-full border border-line bg-field px-[13px] py-[5px] text-[12px] font-semibold text-ink [&_b]:text-ink-strong',
  pick: 'gap-1.5 rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold max-sm:min-h-[38px]',
  act: 'gap-1.5 rounded-[9px] border border-line bg-field px-3 py-[7px] text-[12.5px] font-semibold text-ink hover:border-line-strong hover:bg-soft max-sm:min-h-[38px]',
  filter: 'gap-1 rounded-lg bg-[#eef2fb] py-[5px] pr-1.5 pl-[11px] text-[12px] font-semibold text-[#33507f] dark:bg-[#1b2333] dark:text-[#a9c1ea]',
}

const PICK_ON = 'border-tab-on bg-tab-on text-white dark:border-rev dark:bg-rev dark:text-rev-fg'
const PICK_OFF = 'border-line bg-field text-muted hover:border-line-strong hover:bg-soft hover:text-ink'

/* Etiquetas y píldoras (.chip, .chip.data, .chip.pick, .chip.act, chips de
   filtro activo). Con onClick es un botón; `pick` se conmuta con `on`. */
export default function Chip({
  variant = 'tag',
  on = false,
  onClick,
  onRemove,
  removeLabel = 'Quitar',
  color,
  icon,
  className = '',
  children,
}: {
  variant?: ChipVariant
  on?: boolean
  onClick?: () => void
  onRemove?: () => void
  removeLabel?: string
  /* Punto de color delante. */
  color?: string
  icon?: ReactNode
  className?: string
  children: ReactNode
}) {
  const clase = `inline-flex max-w-full shrink-0 items-center whitespace-nowrap transition-colors ${VAR[variant]} ${variant === 'pick' ? (on ? PICK_ON : PICK_OFF) : ''} ${className}`
  const contenido = (
    <>
      {color && <span className="size-[7px] shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />}
      {icon && <span className="flex shrink-0 [&>svg]:size-3.5">{icon}</span>}
      <span className="truncate">{children}</span>
    </>
  )
  if (onRemove) {
    return (
      <span className={clase}>
        {contenido}
        <button
          type="button"
          onClick={onRemove}
          aria-label={`${removeLabel}: ${typeof children === 'string' ? children : ''}`.replace(/: $/, '')}
          className="ml-0.5 flex size-[18px] items-center justify-center rounded-md opacity-70 transition-colors hover:text-[#e5484d] hover:opacity-100"
        >
          <X className="size-3" strokeWidth={2.4} />
        </button>
      </span>
    )
  }
  if (onClick) {
    return (
      <button type="button" onClick={onClick} aria-pressed={variant === 'pick' ? on : undefined} className={clase}>
        {contenido}
      </button>
    )
  }
  return <span className={clase}>{contenido}</span>
}
