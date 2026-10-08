import type { ReactNode } from 'react'

type Variante = 'card' | 'inline' | 'dashed' | 'compact'

const CAJA: Record<Variante, string> = {
  card: 'rounded-2xl border border-line bg-card px-6 py-10 shadow-empty',
  dashed: 'rounded-[18px] border border-dashed border-[#d4d8de] px-[26px] py-14 dark:border-line-strong',
  inline: 'px-6 py-10',
  compact: 'px-2.5 py-[22px]',
}

/* Estado vacío del ERP (erp_empty): icono en caja gris, título, texto y
   acciones. `compact` es el de las tarjetas del dashboard. */
export default function EmptyState({
  icon,
  title,
  text,
  actions,
  variant = 'card',
  className = '',
}: {
  icon?: ReactNode
  title: ReactNode
  text?: ReactNode
  actions?: ReactNode
  variant?: Variante
  className?: string
}) {
  const compacto = variant === 'compact'
  return (
    <div className={`text-center ${CAJA[variant]} ${className}`}>
      {icon && (
        <div
          aria-hidden="true"
          className={`mx-auto flex items-center justify-center bg-soft text-label ${
            compacto ? 'mb-2.5 size-[38px] rounded-[11px] [&>svg]:size-[18px]' : 'mb-4 size-[60px] rounded-[18px] [&>svg]:size-7'
          }`}
        >
          {icon}
        </div>
      )}
      <p className={compacto ? 'text-[13.5px] font-bold text-ink-strong' : 'text-[18px] font-semibold tracking-[-.3px] text-ink-strong'}>{title}</p>
      {text && <p className={`mx-auto mt-[7px] leading-[1.6] text-muted ${compacto ? 'max-w-[240px] text-[12.5px]' : 'max-w-[400px] text-[13.5px]'}`}>{text}</p>}
      {actions && <div className="mt-4 flex flex-wrap items-center justify-center gap-2.5">{actions}</div>}
    </div>
  )
}
