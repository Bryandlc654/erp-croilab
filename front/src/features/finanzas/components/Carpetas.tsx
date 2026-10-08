import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { ChevronRight, Folder } from 'lucide-react'
import { mesLabel } from '../../../shared/lib/formato'
import { eurC } from '../lib/importes'

/* Tarjeta grande de hub (.fc-hub): emisor, Ingresos o Gastos. */
export function HubCard({ to, icono, color, titulo, sub, stats }: { to: string; icono: ReactNode; color: string; titulo: string; sub: string; stats: { label: string; valor: string }[] }) {
  return (
    <Link
      to={to}
      className="group relative flex min-h-[300px] flex-col rounded-[22px] border border-line bg-card px-10 py-11 transition-[transform,box-shadow,border-color] duration-200 ease-erp hover:-translate-y-1 hover:border-[#d7d7db] hover:shadow-[0_18px_48px_rgba(0,0,0,.09)] max-sm:min-h-0 max-sm:flex-row max-sm:items-center max-sm:gap-4 max-sm:rounded-2xl max-sm:p-4 dark:hover:border-line-strong dark:hover:shadow-[0_18px_48px_rgba(0,0,0,.5)]"
    >
      <span className="flex size-[70px] shrink-0 items-center justify-center rounded-[20px] text-white max-sm:size-11 max-sm:rounded-xl [&>svg]:size-8 max-sm:[&>svg]:size-5" style={{ backgroundColor: color }}>
        {icono}
      </span>
      <ChevronRight className="absolute top-10 right-10 size-5 text-ink transition-transform group-hover:translate-x-0.5 max-sm:static max-sm:order-last max-sm:ml-auto" />
      <div className="mt-auto max-sm:mt-0 max-sm:min-w-0">
        <h2 className="mt-8 text-[26px] leading-tight font-[780] tracking-[-.6px] text-ink-strong max-sm:mt-0 max-sm:truncate max-sm:text-[17px]">{titulo}</h2>
        <p className="mt-1 text-[13.5px] text-muted max-sm:text-[12.5px]">{sub}</p>
        <div className="mt-7 flex flex-wrap gap-10 max-sm:mt-2 max-sm:gap-4">
          {stats.map((s) => (
            <div key={s.label}>
              <b className="block text-[20px] font-[750] text-ink-strong tabular-nums max-sm:text-[14px]">{s.valor}</b>
              <span className="text-[11px] font-semibold tracking-[.5px] text-muted uppercase max-sm:text-[10px]">{s.label}</span>
            </div>
          ))}
        </div>
      </div>
    </Link>
  )
}

/* Carpeta de un mes (.fc-mon): «Marzo 2026», n documentos, total y neto. */
export function CarpetaMes({ to, mes, n, total, neto }: { to: string; mes: string; n: number; total: number; neto: number }) {
  return (
    <Link
      to={to}
      className="block rounded-2xl border border-line bg-card px-[22px] pt-6 pb-[22px] transition-[transform,box-shadow] duration-200 ease-erp hover:-translate-y-[3px] hover:shadow-[0_12px_34px_rgba(0,0,0,.08)] dark:hover:shadow-[0_12px_34px_rgba(0,0,0,.5)]"
    >
      <Folder className="size-7 text-label" strokeWidth={1.6} />
      <h3 className="mt-3 text-[16px] font-semibold text-ink-strong">{mes === 'sin-fecha' ? 'Sin fecha' : mesLabel(mes)}</h3>
      <p className="text-[12.5px] text-muted">
        {n} doc{n === 1 ? '' : 's'}
      </p>
      <b className="mt-3 block text-[19px] font-[750] text-ink-strong tabular-nums">{eurC(total)}</b>
      <span className="text-[12px] text-muted">Neto {eurC(neto)}</span>
    </Link>
  )
}

/* Migas dentro del h1 (Facturas › Víctor › Ingresos) como en facturas.php. */
export function TituloMigas({ partes }: { partes: { label: string; to?: string }[] }) {
  return (
    <span className="flex flex-wrap items-center gap-x-1">
      {partes.map((p, i) => (
        <span key={i} className="inline-flex items-center gap-1">
          {i > 0 && <ChevronRight className="size-5 text-label" strokeWidth={2.2} />}
          {p.to ? (
            <Link to={p.to} className="text-muted transition-colors hover:text-ink-strong">
              {p.label}
            </Link>
          ) : (
            <span>{p.label}</span>
          )}
        </span>
      ))}
    </span>
  )
}
