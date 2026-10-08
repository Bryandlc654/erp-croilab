import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'

/* Tarjeta de acceso rápido (.acc-card): icono blanco sobre color sólido,
   título, descripción y «Abrir ›». `compact` es la de la ficha de cliente (.cf-q). */
export default function QuickActionCard({
  icon,
  color,
  title,
  description,
  to,
  cta = 'Abrir',
  compact = false,
  className = '',
}: {
  icon: ReactNode
  color: string
  title: ReactNode
  description?: ReactNode
  to: string
  cta?: string
  compact?: boolean
  className?: string
}) {
  return (
    <Link
      to={to}
      className={`group flex flex-col border border-line bg-card transition-[transform,box-shadow,border-color] duration-150 ease-erp hover:-translate-y-0.5 hover:shadow-[0_14px_30px_-20px_rgba(0,0,0,.4)] dark:hover:border-line-strong ${
        compact ? 'gap-2.5 rounded-[14px] p-[18px]' : 'gap-3.5 rounded-2xl p-6'
      } ${className}`}
    >
      <span
        className={`flex items-center justify-center text-white ${compact ? 'size-[34px] rounded-[10px] [&>svg]:size-[17px]' : 'size-11 rounded-[13px] [&>svg]:size-[21px]'}`}
        style={{ backgroundColor: color }}
        aria-hidden="true"
      >
        {icon}
      </span>
      <span>
        <b className={`block font-[650] text-ink-strong ${compact ? 'text-[13.5px]' : 'text-[15px]'}`}>{title}</b>
        {description && <span className="mt-1 block text-[12px] leading-[1.5] text-muted">{description}</span>}
      </span>
      {!compact && (
        <span className="mt-auto inline-flex items-center gap-0.5 text-[12.5px] font-semibold text-[#6b7280] transition-colors group-hover:text-ink dark:text-muted">
          {cta} <ChevronRight className="size-3.5" />
        </span>
      )}
    </Link>
  )
}
