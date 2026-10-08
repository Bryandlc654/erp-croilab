import type { ReactNode } from 'react'

/* KPI de Proyectos (proyectos.php): etiqueta con icono y cifra grande. */
export default function KpiIcono({ icono, label, valor, tono }: { icono: ReactNode; label: string; valor: string; tono?: 'ok' | 'mal' }) {
  return (
    <div className="rounded-[20px] border border-line bg-card px-6 py-[22px] max-sm:px-4">
      <span className="flex items-center gap-1.5 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase [&>svg]:size-3.5">
        {icono}
        {label}
      </span>
      <b className={`mt-3 block text-[29px] font-[750] tracking-[-.7px] tabular-nums max-sm:text-[24px] ${tono === 'ok' ? 'text-[#12854a] dark:text-ok' : tono === 'mal' ? 'text-[#e5484d] dark:text-danger' : 'text-ink-strong'}`}>{valor}</b>
    </div>
  )
}
