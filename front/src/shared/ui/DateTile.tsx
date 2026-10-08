import { aFecha, fechaLarga } from '../lib/formato'

const MES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']

/* Tesela de fecha (.dsh-date / .reu-day): día grande y mes abreviado.
   past: vencida (rojo) · ok: hecha (verde). */
export default function DateTile({ date, tone, size = 44, className = '' }: { date: string | Date; tone?: 'past' | 'ok'; size?: number; className?: string }) {
  const d = aFecha(date)
  if (!d) return null
  const color = tone === 'past' ? 'bg-[#fdecec] text-[#c0343a] dark:bg-danger-bg dark:text-danger' : tone === 'ok' ? 'bg-[#e7f7ee] text-[#0f7a3d] dark:bg-ok-bg dark:text-ok' : 'bg-soft text-ink-strong'
  return (
    <span
      className={`flex shrink-0 flex-col items-center justify-center rounded-[10px] leading-none ${color} ${className}`}
      style={{ width: size, height: size }}
      aria-label={fechaLarga(d)}
      role="img"
    >
      <span className="text-[15px] font-bold">{d.getDate()}</span>
      <span className="mt-[3px] text-[9.5px] font-bold tracking-[.4px] uppercase opacity-80">{MES[d.getMonth()]}</span>
    </span>
  )
}
