import { useRef, type KeyboardEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { CAJA_SEGMENTADO as CAJA, claseSegmento, type VarianteSegmentado as Variante } from './clases'

export type SegmentItem<V extends string> = {
  value: V
  label: ReactNode
  icon?: ReactNode
  count?: number
  /* Con href cada segmento es un enlace (vistas por URL). */
  href?: string
  disabled?: boolean
}

function Contador({ n, on, variant }: { n: number; on: boolean; variant: Variante }) {
  return (
    <span
      className={`rounded-full px-[7px] text-[11px] leading-[1.5] font-bold ${
        on && variant === 'dark' ? 'bg-white/[.18] text-white dark:bg-black/10 dark:text-rev-fg' : 'bg-soft text-muted'
      }`}
    >
      {n}
    </span>
  )
}

/* Segmentado (.seg) y sus variantes: píldora gris de los modales y pestañas
   subrayadas. Se comporta como un grupo de radio: flechas para moverse. */
export default function Segmented<V extends string>({
  items,
  value,
  onChange,
  variant = 'dark',
  'aria-label': ariaLabel,
  className = '',
  children,
}: {
  items: SegmentItem<V>[]
  value: V
  onChange?: (v: V) => void
  variant?: Variante
  'aria-label'?: string
  className?: string
  /* Piezas extra al final del grupo (un menú, un botón «+»). */
  children?: ReactNode
}) {
  const caja = useRef<HTMLDivElement>(null)
  const sonEnlaces = items.some((i) => i.href)

  function onKey(e: KeyboardEvent) {
    if (sonEnlaces || !onChange) return
    const dir = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 0
    if (!dir) return
    e.preventDefault()
    const activos = items.filter((i) => !i.disabled)
    const i = activos.findIndex((x) => x.value === value)
    const sig = activos[(i + dir + activos.length) % activos.length]
    if (!sig) return
    onChange(sig.value)
    const botones = Array.from(caja.current?.querySelectorAll<HTMLElement>('[data-valor]') ?? [])
    botones.find((b) => b.dataset.valor === sig.value)?.focus()
  }

  return (
    <div
      ref={caja}
      role={sonEnlaces ? undefined : 'radiogroup'}
      aria-label={ariaLabel}
      onKeyDown={onKey}
      className={`${CAJA[variant]} ${className}`}
    >
      {items.map((it) => {
        const on = it.value === value
        const contenido = (
          <>
            {it.icon}
            {it.label}
            {/* El espacio separa etiqueta y número en el nombre accesible («Todas 24»); en flex no se ve. */}
            {it.count !== undefined && ' '}
            {it.count !== undefined && <Contador n={it.count} on={on} variant={variant} />}
          </>
        )
        const clase = `${claseSegmento(on, variant)} disabled:pointer-events-none disabled:opacity-50`
        if (it.href) {
          return (
            <Link key={it.value} to={it.href} aria-current={on ? 'page' : undefined} className={clase}>
              {contenido}
            </Link>
          )
        }
        return (
          <button
            key={it.value}
            type="button"
            role="radio"
            aria-checked={on}
            data-valor={it.value}
            tabIndex={on ? 0 : -1}
            disabled={it.disabled}
            onClick={() => !on && onChange?.(it.value)}
            className={clase}
          >
            {contenido}
          </button>
        )
      })}
      {children}
    </div>
  )
}
