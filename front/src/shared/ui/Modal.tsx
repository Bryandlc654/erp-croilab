import { useId, useMemo, useRef, type ReactNode, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { useCapa, useTrampaFoco } from './capas'
import { ModalContext, useModalActual } from './modalContexto'

export type ModalSize = 'sm' | 'md' | 'lg' | 'xl' | 'full'

// Tamaños unificados (§2.B «Superposiciones»): 380 / 440 / 520 / 640 / 1020.
const ANCHO: Record<ModalSize, string> = {
  sm: 'w-[380px]',
  md: 'w-[440px]',
  lg: 'w-[520px]',
  xl: 'w-[640px]',
  full: 'w-[1020px] max-w-[95vw]',
}

type Props = {
  open: boolean
  onClose: () => void
  size?: ModalSize
  /* Con título se pinta la cabecera; si no, componer con ModalHeader. */
  title?: ReactNode
  subtitle?: ReactNode
  logo?: ReactNode
  /* top: alineado arriba (formularios largos). */
  align?: 'center' | 'top'
  initialFocus?: RefObject<HTMLElement | null>
  closeOnMask?: boolean
  'aria-label'?: string
  className?: string
  children: ReactNode
}

/* Modal del ERP (.erpag): máscara con desenfoque, caja de radio 18 que entra
   subiendo un poco. Esc, clic en la máscara, foco atrapado dentro y devuelto
   al cerrar; en el móvil ocupa toda la pantalla. */
export default function Modal(props: Props) {
  if (!props.open) return null
  return <ModalAbierto {...props} />
}

function ModalAbierto({
  onClose,
  size = 'md',
  title,
  subtitle,
  logo,
  align = 'center',
  initialFocus,
  closeOnMask = true,
  'aria-label': ariaLabel,
  className = '',
  children,
}: Props) {
  const caja = useRef<HTMLDivElement>(null)
  const tituloId = useId()
  useCapa(true, { onEscape: onClose, bloquearScroll: true })
  useTrampaFoco(caja, true, initialFocus)
  const ctx = useMemo(() => ({ tituloId, cerrar: onClose }), [tituloId, onClose])
  const conTitulo = title !== undefined

  return createPortal(
    <div
      className={`fixed inset-0 z-[1200] flex justify-center overflow-y-auto bg-[rgba(16,19,24,.36)] p-5 backdrop-blur-[4px] motion-safe:animate-fade-in max-sm:p-0 dark:bg-black/60 ${
        align === 'top' ? 'items-start pt-14' : 'items-center'
      }`}
      onMouseDown={(e) => {
        // Solo si el clic empieza y acaba en la máscara (arrastrar texto fuera no cierra).
        if (closeOnMask && e.target === e.currentTarget) onClose()
      }}
    >
      <div
        ref={caja}
        role="dialog"
        aria-modal="true"
        aria-labelledby={conTitulo || !ariaLabel ? tituloId : undefined}
        aria-label={ariaLabel}
        tabIndex={-1}
        className={`relative flex max-w-full flex-col overflow-hidden rounded-[18px] bg-card shadow-modal outline-none motion-safe:animate-dlg-in max-sm:min-h-full max-sm:w-full max-sm:rounded-none dark:border dark:border-line ${ANCHO[size]} ${className}`}
      >
        <ModalContext.Provider value={ctx}>
          {conTitulo && <ModalHeader title={title} subtitle={subtitle} logo={logo} />}
          {children}
        </ModalContext.Provider>
      </div>
    </div>,
    document.body,
  )
}

export function ModalHeader({ title, subtitle, logo, children }: { title: ReactNode; subtitle?: ReactNode; logo?: ReactNode; children?: ReactNode }) {
  const m = useModalActual()
  return (
    <div className="border-b border-line px-[22px] py-[17px]">
      <div className="flex items-center gap-2.5">
        {logo && <span className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-line">{logo}</span>}
        <div className="min-w-0 flex-1">
          <h2 id={m?.tituloId} className="text-[16px] font-bold text-ink-strong">
            {title}
          </h2>
          {subtitle && <p className="mt-0.5 text-[11.5px] text-muted">{subtitle}</p>}
        </div>
        {m && (
          <button type="button" onClick={m.cerrar} aria-label="Cerrar" className="flex size-8 shrink-0 items-center justify-center rounded-[9px] text-label transition-colors hover:bg-soft hover:text-ink">
            <X className="size-[18px]" />
          </button>
        )}
      </div>
      {children && <div className="mt-3.5">{children}</div>}
    </div>
  )
}

export function ModalBody({ className = '', children }: { className?: string; children: ReactNode }) {
  return <div className={`flex min-h-0 flex-1 flex-col gap-[15px] overflow-y-auto px-[22px] py-[18px] ${className}`}>{children}</div>
}

export function ModalFooter({ className = '', children }: { className?: string; children: ReactNode }) {
  return <div className={`flex flex-wrap items-center justify-end gap-2.5 border-t border-line px-[22px] py-[15px] max-sm:sticky max-sm:bottom-0 max-sm:bg-card ${className}`}>{children}</div>
}
