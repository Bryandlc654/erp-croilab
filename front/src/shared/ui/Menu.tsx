import { useEffect, useRef, useState, type ReactNode } from 'react'

/* Desplegable sencillo: un disparador y un panel que se cierra al pulsar fuera
   o con Escape. `align` decide hacia qué lado se abre. */
export default function Menu({
  trigger,
  children,
  align = 'left',
  disabled = false,
  className = '',
  panelClassName = '',
  label,
}: {
  trigger: (abierto: boolean) => ReactNode
  children: (cerrar: () => void) => ReactNode
  align?: 'left' | 'right'
  disabled?: boolean
  className?: string
  panelClassName?: string
  label?: string
}) {
  const [abierto, setAbierto] = useState(false)
  const raiz = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!abierto) return
    const fuera = (e: MouseEvent) => {
      if (!raiz.current?.contains(e.target as Node)) setAbierto(false)
    }
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && setAbierto(false)
    document.addEventListener('mousedown', fuera)
    document.addEventListener('keydown', esc)
    return () => {
      document.removeEventListener('mousedown', fuera)
      document.removeEventListener('keydown', esc)
    }
  }, [abierto])

  return (
    <div ref={raiz} className={`relative ${className}`} onClick={(e) => e.stopPropagation()}>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setAbierto((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={abierto}
        aria-label={label}
        className="flex w-full items-center rounded-[7px] text-left disabled:cursor-default"
      >
        {trigger(abierto)}
      </button>
      {abierto && (
        <div
          role="menu"
          className={`absolute top-full z-30 mt-1 min-w-[180px] rounded-xl border border-line bg-page p-1.5 shadow-[0_12px_32px_-8px_rgba(0,0,0,.18)] ${
            align === 'right' ? 'right-0' : 'left-0'
          } ${panelClassName}`}
        >
          {children(() => setAbierto(false))}
        </div>
      )}
    </div>
  )
}

export function MenuItem({ onClick, children, on = false }: { onClick: () => void; children: ReactNode; on?: boolean }) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onClick}
      className={`flex w-full items-center gap-[9px] rounded-lg px-2.5 py-2 text-left text-[13px] font-medium hover:bg-soft hover:text-ink ${
        on ? 'bg-soft text-ink' : 'text-[#4c515b] dark:text-zinc-300'
      }`}
    >
      {children}
    </button>
  )
}
