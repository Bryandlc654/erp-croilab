import { ChevronLeft, ChevronRight } from 'lucide-react'
import { mesLabel } from '../../../shared/lib/formato'
import { sumarMeses, ymDe } from '../lib/periodos'

/* Navegador de mes «‹ Marzo 2026 ›» + «Hoy» si no es el actual (fin-resumen, fin-horas). */
export default function MonthNav({ mes, onChange }: { mes: string; onChange: (ym: string) => void }) {
  const actual = ymDe()
  const boton = 'flex size-8 items-center justify-center rounded-lg text-ink transition-colors hover:bg-soft [&>svg]:size-4'
  return (
    <div className="inline-flex items-center gap-1 rounded-[11px] border border-line bg-card p-1">
      <button type="button" className={boton} onClick={() => onChange(sumarMeses(mes, -1))} aria-label="Mes anterior">
        <ChevronLeft />
      </button>
      <span className="min-w-[140px] text-center text-[13.5px] font-semibold text-ink-strong" aria-live="polite">
        {mesLabel(mes)}
      </span>
      <button type="button" className={boton} onClick={() => onChange(sumarMeses(mes, 1))} aria-label="Mes siguiente">
        <ChevronRight />
      </button>
      {mes !== actual && (
        <button type="button" onClick={() => onChange(actual)} className="rounded-lg px-2.5 py-1.5 text-[12.5px] font-semibold text-accent hover:bg-soft">
          Hoy
        </button>
      )}
    </div>
  )
}
