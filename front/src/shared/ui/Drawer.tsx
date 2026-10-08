import { useId, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { useCapa, useTrampaFoco } from './capas'

type Props = {
  open: boolean
  onClose: () => void
  width?: number
  side?: 'right' | 'left'
  title?: ReactNode
  subtitle?: ReactNode
  /* Acciones en la cabecera, antes del botón de cerrar. */
  actions?: ReactNode
  'aria-label'?: string
  className?: string
  children: ReactNode
}

/* Panel lateral (detalle rápido de un registro): entra por el lado, Esc o clic
   fuera cierran y el foco queda dentro mientras está abierto. */
export default function Drawer(props: Props) {
  if (!props.open) return null
  return <DrawerAbierto {...props} />
}

function DrawerAbierto({ onClose, width = 520, side = 'right', title, subtitle, actions, 'aria-label': ariaLabel, className = '', children }: Props) {
  const panel = useRef<HTMLDivElement>(null)
  const id = useId()
  useCapa(true, { onEscape: onClose, bloquearScroll: true })
  useTrampaFoco(panel, true)

  return createPortal(
    <div className={`fixed inset-0 z-[1100] flex ${side === 'right' ? 'justify-end' : 'justify-start'}`}>
      <button type="button" tabIndex={-1} aria-hidden="true" className="absolute inset-0 bg-black/20 motion-safe:animate-fade-in dark:bg-black/50" onClick={onClose} />
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? `${id}-t` : undefined}
        aria-label={title ? undefined : ariaLabel}
        tabIndex={-1}
        style={{ maxWidth: width }}
        className={`relative flex h-full w-full flex-col overflow-y-auto bg-card shadow-2xl outline-none ${
          side === 'right' ? 'border-l border-line motion-safe:animate-drawer-in' : 'border-r border-line motion-safe:animate-slide-in'
        } ${className}`}
      >
        {title !== undefined && (
          <div className="sticky top-0 z-[1] flex items-start gap-3 border-b border-line bg-card px-6 py-5">
            <div className="min-w-0 flex-1">
              {subtitle && <p className="mb-1 truncate text-[12px] font-medium text-muted">{subtitle}</p>}
              <h2 id={`${id}-t`} className="text-[19px] leading-snug font-semibold text-ink-strong">
                {title}
              </h2>
            </div>
            {actions}
            <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-label transition-colors hover:bg-soft hover:text-ink" aria-label="Cerrar">
              <X className="size-5" />
            </button>
          </div>
        )}
        {children}
      </div>
    </div>,
    document.body,
  )
}
