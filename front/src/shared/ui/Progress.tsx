/* Barras de progreso del ERP (no las hay de carga: solo de avance). */

/* Barra continua de 6px. */
export function ProgressBar({ value, max = 100, label, className = '', color }: { value: number; max?: number; label?: string; className?: string; color?: string }) {
  const pct = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0
  return (
    <div
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={max}
      aria-valuenow={value}
      aria-label={label}
      className={`h-1.5 w-full overflow-hidden rounded-full bg-[#eceef1] dark:bg-line-strong ${className}`}
    >
      <div className="h-full rounded-full bg-accent transition-[width] duration-300 ease-erp" style={{ width: `${pct}%`, backgroundColor: color }} />
    </div>
  )
}

/* Barra de fases por segmentos (.cf-pbar): `current` fases hechas de `total`. */
export function PhaseBar({ total, current, label, className = '' }: { total: number; current: number; label?: string; className?: string }) {
  return (
    <div role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={current} aria-label={label} className={`flex gap-[5px] ${className}`}>
      {Array.from({ length: total }, (_, i) => (
        <span key={i} className={`h-1.5 flex-1 rounded-full transition-colors ${i < current ? 'bg-accent' : 'bg-[#eceef1] dark:bg-line-strong'}`} />
      ))}
    </div>
  )
}

/* Píldora «3/5» del checklist (.chk-prog). */
export function ProgressPill({ done, total, className = '' }: { done: number; total: number; className?: string }) {
  const completo = total > 0 && done >= total
  return (
    <span
      className={`inline-block rounded-full px-[9px] py-0.5 text-[11px] font-bold tabular-nums ${completo ? 'bg-ok-bg text-[#12854a] dark:text-ok' : 'bg-soft text-muted'} ${className}`}
      aria-label={`${done} de ${total} hechas`}
    >
      {done}/{total}
    </span>
  )
}
