import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Bell, X } from 'lucide-react'

export type AvisoEnVivo = {
  id: string | number
  titulo: string
  subtitulo?: string
  icono?: ReactNode
  onClick?: () => void
}

const DURA = 6500
const RESTO = 2500

/* Pila de avisos en vivo arriba a la derecha (#notifPops): cada tarjeta dura
   6,5 s; con el ratón encima se pausa y al salir quedan 2,5 s. Con más de 3 se
   difumina la parte de arriba. Los datos llegan por props (quien sondee la API
   de avisos decide qué enseñar). */
export default function NotificationStack({ items, onDismiss }: { items: AvisoEnVivo[]; onDismiss: (id: AvisoEnVivo['id']) => void }) {
  if (items.length === 0) return null
  return (
    <div
      aria-live="polite"
      className="fixed top-14 right-1.5 z-[600] flex max-h-[264px] w-[362px] max-w-[calc(100vw-12px)] flex-col gap-[11px] overflow-y-auto px-4 pt-2 pb-[18px] max-sm:right-0"
      style={items.length > 3 ? { maskImage: 'linear-gradient(to bottom, transparent 0, #000 26px)' } : undefined}
    >
      {items.slice(-6).map((a) => (
        <Tarjeta key={a.id} aviso={a} onDismiss={() => onDismiss(a.id)} />
      ))}
    </div>
  )
}

function Tarjeta({ aviso, onDismiss }: { aviso: AvisoEnVivo; onDismiss: () => void }) {
  const [encima, setEncima] = useState(false)
  const [restante, setRestante] = useState(DURA)
  const cerrar = useRef(onDismiss)
  useEffect(() => {
    cerrar.current = onDismiss
  })

  useEffect(() => {
    if (encima) return
    const t = setTimeout(() => cerrar.current(), restante)
    return () => clearTimeout(t)
  }, [encima, restante])

  return (
    <div
      role="status"
      onMouseEnter={() => setEncima(true)}
      onMouseLeave={() => {
        setEncima(false)
        setRestante(RESTO)
      }}
      className="group flex shrink-0 cursor-pointer items-start gap-[11px] rounded-[14px] border border-line bg-card px-3.5 py-[13px] shadow-notif transition-colors motion-safe:animate-erp-in hover:border-line-strong"
      onClick={() => {
        aviso.onClick?.()
        onDismiss()
      }}
    >
      <span className="flex size-[34px] shrink-0 items-center justify-center rounded-[10px] bg-rev text-rev-fg [&>svg]:size-4" aria-hidden="true">
        {aviso.icono ?? <Bell />}
      </span>
      <span className="min-w-0 flex-1">
        <b className="block text-[13px] font-[650] text-ink-strong">{aviso.titulo}</b>
        {aviso.subtitulo && <span className="mt-0.5 block truncate text-[12px] text-muted">{aviso.subtitulo}</span>}
      </span>
      <button
        type="button"
        aria-label="Cerrar aviso"
        onClick={(e) => {
          e.stopPropagation()
          onDismiss()
        }}
        className="flex size-5 shrink-0 items-center justify-center rounded-md text-[#c2c6cd] transition-colors hover:text-ink"
      >
        <X className="size-3.5" />
      </button>
    </div>
  )
}
