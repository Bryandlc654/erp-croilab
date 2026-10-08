import type { ReactNode } from 'react'
import Breadcrumbs, { type Miga } from './Breadcrumbs'

/* Cabecera de página: migas opcionales, h1 de 26px con su subtítulo y las
   acciones a la derecha (botón «+ Nuevo»…). */
export default function PageHeader({
  title,
  lead,
  avatar,
  actions,
  crumbs,
  className = '',
}: {
  title: ReactNode
  lead?: ReactNode
  avatar?: ReactNode
  actions?: ReactNode
  crumbs?: Miga[]
  className?: string
}) {
  return (
    <header className={`mb-[26px] ${className}`}>
      {crumbs && crumbs.length > 0 && <Breadcrumbs items={crumbs} />}
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
        <div className="flex min-w-0 items-center gap-3.5">
          {avatar && <div className="shrink-0">{avatar}</div>}
          <div className="min-w-0">
            <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">{title}</h1>
            {lead && <p className="mt-2 max-w-[75ch] text-[15px] leading-[1.55] text-muted">{lead}</p>}
          </div>
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2.5">{actions}</div>}
      </div>
    </header>
  )
}
