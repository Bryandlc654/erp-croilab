import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

/* Fila de lista con algo a la izquierda (icono, avatar o tesela de fecha),
   título y subtítulo, y un valor o acciones a la derecha (.dsh-row, .cf-row,
   .pp-r). Con `to` toda la fila es un enlace. */
export default function ListRow({
  leading,
  icon,
  iconColor,
  title,
  subtitle,
  right,
  actions,
  to,
  onClick,
  className = '',
}: {
  /* Avatar, DateTile… tal cual. */
  leading?: ReactNode
  /* Icono en caja de 30px (fondo suave o del color con transparencia). */
  icon?: ReactNode
  iconColor?: string
  title: ReactNode
  subtitle?: ReactNode
  right?: ReactNode
  /* Visibles al pasar el ratón (siempre en el móvil). */
  actions?: ReactNode
  to?: string
  onClick?: () => void
  className?: string
}) {
  const cuerpo = (
    <>
      {leading}
      {icon && (
        <span
          className={`flex size-[30px] shrink-0 items-center justify-center rounded-[9px] [&>svg]:size-[15px] ${iconColor ? '' : 'bg-soft text-muted'}`}
          style={iconColor ? { backgroundColor: `${iconColor}1e`, color: iconColor } : undefined}
          aria-hidden="true"
        >
          {icon}
        </span>
      )}
      <span className="min-w-0 flex-1">
        <span className={`block truncate text-[13.5px] font-semibold text-ink-strong ${to || onClick ? 'transition-colors group-hover/row:text-[#0071e3] dark:group-hover/row:text-[#6aa8ff]' : ''}`}>{title}</span>
        {subtitle && <span className="mt-0.5 block truncate text-[12px] text-muted">{subtitle}</span>}
      </span>
      {right && <span className="shrink-0 text-[12.5px] font-semibold text-muted">{right}</span>}
    </>
  )
  const clase = `group/row flex items-center gap-3.5 border-t border-line2 px-0.5 py-[15px] first:border-t-0 ${className}`
  const acciones = actions && (
    <span className="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-focus-within/row:opacity-100 group-hover/row:opacity-100 max-[760px]:opacity-100">{actions}</span>
  )
  if (to) {
    return (
      <div className={clase}>
        <Link to={to} className="flex min-w-0 flex-1 items-center gap-3.5">
          {cuerpo}
        </Link>
        {acciones}
      </div>
    )
  }
  if (onClick) {
    return (
      <div className={clase}>
        <button type="button" onClick={onClick} className="flex min-w-0 flex-1 items-center gap-3.5 text-left">
          {cuerpo}
        </button>
        {acciones}
      </div>
    )
  }
  return (
    <div className={clase}>
      {cuerpo}
      {acciones}
    </div>
  )
}
