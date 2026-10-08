import type { ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'

type Props = {
  color: string
  label: ReactNode
  /* solid: fondo del color y texto blanco (fase CRM) · tint: fondo al ~9% y
     texto del color (facturas, tickets) · neutral: gris con punto (.gpill). */
  variant?: 'solid' | 'tint' | 'neutral'
  dot?: boolean
  chevron?: boolean
  open?: boolean
  size?: 'sm' | 'md'
  onClick?: () => void
  className?: string
  'aria-label'?: string
}

/* Píldora de estado en sus tres estilos del ERP. Con onClick es un botón
   (cambiar fase/estado desde la propia píldora). */
export default function StatusPill({ color, label, variant = 'tint', dot, chevron = false, open = false, size = 'md', onClick, className = '', 'aria-label': ariaLabel }: Props) {
  const conPunto = dot ?? variant !== 'tint'
  const estilo =
    variant === 'solid'
      ? { backgroundColor: color, color: '#fff' }
      : variant === 'tint'
        ? { backgroundColor: `${color}18`, color }
        : undefined
  const clase =
    variant === 'neutral'
      ? `gap-[7px] rounded-[7px] bg-soft px-[11px] py-1 text-[11px] font-bold tracking-[.4px] text-ink uppercase`
      : `gap-1.5 rounded-full font-bold ${size === 'sm' ? 'px-[9px] py-[3px] text-[10.5px]' : 'px-2.5 py-1 text-[11.5px]'} ${variant === 'tint' ? '!font-semibold' : ''}`
  const contenido = (
    <>
      {conPunto && (
        <span
          aria-hidden="true"
          className={variant === 'neutral' ? 'size-2 shrink-0 rounded-[3px]' : 'size-[7px] shrink-0 rounded-full opacity-90'}
          style={{ backgroundColor: variant === 'neutral' ? color : 'currentColor' }}
        />
      )}
      <span className="truncate">{label}</span>
      {chevron && <ChevronDown className={`size-2.5 shrink-0 opacity-75 transition-transform duration-[140ms] ${open ? 'rotate-180' : ''}`} strokeWidth={2.6} aria-hidden="true" />}
    </>
  )
  const base = `inline-flex max-w-full shrink-0 items-center whitespace-nowrap leading-[1.35] ${clase} ${className}`
  if (onClick) {
    return (
      <button
        type="button"
        onClick={onClick}
        aria-label={ariaLabel}
        aria-expanded={chevron ? open : undefined}
        className={`${base} transition-shadow hover:shadow-[0_0_0_3px_rgba(0,0,0,.08)] ${open ? 'shadow-[0_0_0_3px_rgba(0,0,0,.14)]' : ''}`}
        style={estilo}
      >
        {contenido}
      </button>
    )
  }
  return (
    <span className={base} style={estilo} aria-label={ariaLabel}>
      {contenido}
    </span>
  )
}
