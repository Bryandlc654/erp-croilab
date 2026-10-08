import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ArrowDownRight, ArrowUpRight } from 'lucide-react'

/* Cifra grande del ERP (.kpi): etiqueta en mayúsculas, número 31px y una línea
   debajo. Con `href` es un enlace al listado que produce la cifra. */
export default function KpiTile({
  label,
  value,
  sub,
  icon,
  href,
  delta,
  tint,
  className = '',
}: {
  label: ReactNode
  value: ReactNode
  sub?: ReactNode
  icon?: ReactNode
  href?: string
  /* Variación frente al periodo anterior: sube en verde, baja en rojo. */
  delta?: { value: ReactNode; dir: 'up' | 'down' }
  /* Fondo teñido (resumen financiero: ingresos, impuestos, neto). */
  tint?: string
  className?: string
}) {
  const clase = `block rounded-2xl border border-line bg-card px-6 py-[22px] transition-[transform,border-color,box-shadow] duration-150 ease-erp max-sm:px-4 max-sm:py-[18px] ${
    href ? 'hover:-translate-y-0.5 hover:border-[#e2e2e4] hover:shadow-card-hover dark:hover:border-line-strong dark:hover:shadow-[0_8px_26px_rgba(0,0,0,.4)]' : ''
  } ${className}`
  const cuerpo = (
    <>
      <div className="flex items-center justify-between gap-2 text-[11.5px] font-semibold tracking-[.4px] text-muted uppercase">
        <span className="truncate">{label}</span>
        {icon && <span className="flex shrink-0 text-label [&>svg]:size-[17px]">{icon}</span>}
      </div>
      <div className="mt-3 text-[31px] leading-none font-[640] tracking-[-.7px] text-ink-strong tabular-nums max-sm:text-[26px]">{value}</div>
      {(sub || delta) && (
        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[12px] text-muted">
          {delta && (
            <span
              className={`inline-flex items-center gap-0.5 rounded-full px-2 py-px text-[11.5px] font-semibold ${
                delta.dir === 'up' ? 'bg-ok-bg text-[#12854a] dark:text-ok' : 'bg-danger-bg text-[#e5484d] dark:text-danger'
              }`}
            >
              {delta.dir === 'up' ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
              {delta.value}
            </span>
          )}
          {sub}
        </div>
      )}
    </>
  )
  const estilo = tint ? { backgroundColor: tint } : undefined
  if (href)
    return (
      <Link to={href} className={clase} style={estilo}>
        {cuerpo}
      </Link>
    )
  return (
    <div className={clase} style={estilo}>
      {cuerpo}
    </div>
  )
}

const COLS = { 2: 'grid-cols-2', 3: 'grid-cols-3', 4: 'grid-cols-4', 5: 'grid-cols-5' }

/* Fila de KPIs (.kpis): 4 → 2 columnas ≤900px → 1 ≤420px. */
export function KpiGrid({ cols = 4, className = '', children }: { cols?: 2 | 3 | 4 | 5; className?: string; children: ReactNode }) {
  return <div className={`mb-6 grid gap-5 ${COLS[cols]} max-[900px]:grid-cols-2 max-sm:gap-3 max-[420px]:grid-cols-1 ${className}`}>{children}</div>
}
