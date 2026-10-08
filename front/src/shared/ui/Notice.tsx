import type { ReactNode } from 'react'
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react'

type Tono = 'ok' | 'error' | 'warn' | 'info'

const TONO: Record<Tono, string> = {
  ok: 'border-[#cfe9d6] bg-[#eafaf0] text-[#12854a] dark:border-ok-line dark:bg-ok-bg dark:text-ok',
  error: 'border-[#f0caca] bg-[#fbeeee] text-[#a32d2d] dark:border-danger-line dark:bg-danger-bg dark:text-danger',
  warn: 'border-[#f3e3b5] bg-[#fffaf0] text-[#8a5a00] dark:border-[#4a3a17] dark:bg-[#2a2210] dark:text-warn',
  info: 'border-line bg-soft text-ink',
}

const ICONO: Record<Tono, ReactNode> = {
  ok: <CheckCircle2 />,
  error: <XCircle />,
  warn: <AlertTriangle />,
  info: <Info />,
}

/* Aviso dentro de la página (.ok-note, .err-note). Los errores se anuncian
   a lectores de pantalla. */
export default function Notice({
  tone = 'info',
  icon,
  title,
  action,
  className = '',
  children,
}: {
  tone?: Tono
  /* false para quitar el icono por defecto. */
  icon?: ReactNode | false
  title?: ReactNode
  action?: ReactNode
  className?: string
  children?: ReactNode
}) {
  const ic = icon === false ? null : (icon ?? ICONO[tone])
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={`mb-4 flex items-start gap-2.5 rounded-[10px] border px-3.5 py-2.5 text-[13px] leading-[1.5] ${TONO[tone]} ${className}`}
    >
      {ic && <span className="mt-px flex shrink-0 [&>svg]:size-4">{ic}</span>}
      <div className="min-w-0 flex-1">
        {title && <b className="block font-semibold">{title}</b>}
        {children}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}
