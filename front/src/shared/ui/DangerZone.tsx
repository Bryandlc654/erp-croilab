import type { ReactNode } from 'react'

/* Zona de peligro: en caja rosada (.te-baja) o simple bajo una línea (.cf-danger). */
export default function DangerZone({ title, text, action, variant = 'box', className = '' }: { title: ReactNode; text?: ReactNode; action: ReactNode; variant?: 'box' | 'simple'; className?: string }) {
  if (variant === 'simple') {
    return (
      <div className={`mt-[26px] flex flex-wrap items-center justify-between gap-4 border-t border-line pt-5 ${className}`}>
        <div className="min-w-0">
          <b className="block text-[13.5px] font-semibold text-ink-strong">{title}</b>
          {text && <p className="mt-0.5 text-[13px] text-muted">{text}</p>}
        </div>
        {action}
      </div>
    )
  }
  return (
    <section
      className={`flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#f2dede] bg-[#fffafa] px-[22px] py-[18px] dark:border-danger-line dark:bg-danger-bg ${className}`}
    >
      <div className="min-w-0 flex-1">
        <h3 className="text-[14.5px] font-semibold text-[#8f3034] dark:text-danger">{title}</h3>
        {text && <p className="mt-1 text-[13px] leading-[1.5] text-[#a86a6c] dark:text-danger/80">{text}</p>}
      </div>
      {action}
    </section>
  )
}
