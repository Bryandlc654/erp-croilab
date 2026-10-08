import { useEffect, useRef, type ReactNode } from 'react'
import { createPortal } from 'react-dom'

/* Desplegable del raíl (.rail-fly): panel de 240px pegado al raíl con el menú
   del módulo. Lo abre/cierra quien lo usa con intención (useIntencion
   380/200 ms); aquí se cierra al elegir un enlace, con Esc o con un clic
   fuera. Los clics dentro que no son enlaces (plegar algo) no lo cierran. */
export default function RailFlyout({
  open,
  title,
  onClose,
  onMouseEnter,
  onMouseLeave,
  left = 80,
  id,
  children,
}: {
  open: boolean
  title: ReactNode
  onClose: () => void
  onMouseEnter?: () => void
  onMouseLeave?: () => void
  left?: number
  id?: string
  children: ReactNode
}) {
  const panel = useRef<HTMLDivElement>(null)
  const cerrar = useRef(onClose)
  useEffect(() => {
    cerrar.current = onClose
  })

  useEffect(() => {
    if (!open) return
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && cerrar.current()
    const fuera = (e: PointerEvent) => {
      const t = e.target
      if (t instanceof Node && t.isConnected && !panel.current?.contains(t)) cerrar.current()
    }
    document.addEventListener('keydown', esc)
    document.addEventListener('pointerdown', fuera, true)
    return () => {
      document.removeEventListener('keydown', esc)
      document.removeEventListener('pointerdown', fuera, true)
    }
  }, [open])

  if (!open) return null
  return createPortal(
    <div
      ref={panel}
      id={id}
      role="navigation"
      aria-label={typeof title === 'string' ? title : undefined}
      onMouseEnter={onMouseEnter}
      onMouseLeave={onMouseLeave}
      onClick={(e) => {
        if ((e.target as HTMLElement).closest('a')) onClose()
      }}
      style={{ left }}
      className="fixed top-2 bottom-2 z-[600] flex w-[240px] flex-col overflow-y-auto rounded-2xl border border-line bg-card pb-3 shadow-fly motion-safe:animate-slide-in dark:shadow-[14px_0_34px_-18px_rgba(0,0,0,.7)]"
    >
      <div className="px-5 pt-[18px] pb-1.5 text-[15.5px] font-[650] tracking-[-.2px] text-ink-strong">{title}</div>
      {children}
    </div>,
    document.body,
  )
}
