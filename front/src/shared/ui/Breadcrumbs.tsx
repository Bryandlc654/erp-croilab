import { Fragment, type ReactNode } from 'react'
import { Link } from 'react-router-dom'

export type Miga = { label: ReactNode; to?: string; icon?: ReactNode }

/* Migas (.tk-crumb): enlaces en gris, separador «/» claro y el último en negrita. */
export default function Breadcrumbs({ items, className = '' }: { items: Miga[]; className?: string }) {
  return (
    <nav aria-label="Migas de pan" className={`mb-4 text-[12.5px] text-muted ${className}`}>
      <ol className="flex flex-wrap items-center gap-2">
        {items.map((m, i) => {
          const ultimo = i === items.length - 1
          return (
            <Fragment key={i}>
              {i > 0 && (
                <li aria-hidden="true" className="text-[#d4d7dd] dark:text-line-strong">
                  /
                </li>
              )}
              <li className="min-w-0">
                {ultimo || !m.to ? (
                  <span className={`inline-flex items-center gap-[5px] ${ultimo ? 'font-semibold text-ink' : ''}`} aria-current={ultimo ? 'page' : undefined}>
                    {m.icon}
                    {m.label}
                  </span>
                ) : (
                  <Link to={m.to} className="inline-flex items-center gap-[5px] transition-colors hover:text-ink [&>svg]:size-3.5">
                    {m.icon}
                    {m.label}
                  </Link>
                )}
              </li>
            </Fragment>
          )
        })}
      </ol>
    </nav>
  )
}
