import { useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronRight } from 'lucide-react'

const DURACION = 300
const CURVA = 'cubic-bezier(.33,1,.68,1)'

function puedeAnimar() {
  if (typeof window === 'undefined' || typeof HTMLElement.prototype.animate !== 'function') return false
  return !(typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
}

/* Plegable con la animación de los acordeones del ERP (§4.20): altura y
   opacidad en 300 ms. Cerrado, el contenido se desmonta (como hasta ahora en
   los grupos de tareas); el recorte solo existe mientras anima, para no cortar
   menús ni arrastres dentro. */
export default function Collapse({ open, children, className = '', id }: { open: boolean; children: ReactNode; className?: string; id?: string }) {
  const caja = useRef<HTMLDivElement>(null)
  const [previo, setPrevio] = useState(open)
  // Mientras se cierra el contenido sigue montado para poder animarlo.
  const [cerrando, setCerrando] = useState(false)
  if (open !== previo) {
    setPrevio(open)
    setCerrando(!open && puedeAnimar())
  }
  const primera = useRef(true)

  useLayoutEffect(() => {
    if (primera.current) {
      primera.current = false
      return
    }
    const el = caja.current
    if (!el || !puedeAnimar()) return
    const alto = el.scrollHeight
    const frames = open
      ? [
          { height: '0px', opacity: 0 },
          { height: `${alto}px`, opacity: 1 },
        ]
      : [
          { height: `${alto}px`, opacity: 1 },
          { height: '0px', opacity: 0 },
        ]
    el.style.overflow = 'hidden'
    const a = el.animate(frames, { duration: DURACION, easing: CURVA })
    a.onfinish = () => {
      el.style.overflow = ''
      if (!open) setCerrando(false)
    }
    return () => {
      a.onfinish = null
      a.cancel()
      el.style.overflow = ''
    }
  }, [open])

  if (!open && !cerrando) return null
  return (
    <div ref={caja} id={id} className={className}>
      {children}
    </div>
  )
}

/* Una sección plegable con su cabecera (acordeón). */
export function AccordionItem({
  title,
  defaultOpen = false,
  open: controlado,
  onOpenChange,
  right,
  className = '',
  children,
}: {
  title: ReactNode
  defaultOpen?: boolean
  open?: boolean
  onOpenChange?: (v: boolean) => void
  right?: ReactNode
  className?: string
  children: ReactNode
}) {
  const [propio, setPropio] = useState(defaultOpen)
  const abierto = controlado ?? propio
  const id = useId()
  return (
    <div className={`border-b border-line2 last:border-b-0 ${className}`}>
      <div className="flex items-center gap-2">
        <button
          type="button"
          aria-expanded={abierto}
          aria-controls={`${id}-panel`}
          onClick={() => {
            if (controlado === undefined) setPropio(!abierto)
            onOpenChange?.(!abierto)
          }}
          className="flex min-w-0 flex-1 items-center gap-2 py-3 text-left text-[13.5px] font-semibold text-ink-strong"
        >
          <ChevronRight className={`size-4 shrink-0 text-muted transition-transform duration-200 ${abierto ? 'rotate-90' : ''}`} aria-hidden="true" />
          <span className="truncate">{title}</span>
        </button>
        {right}
      </div>
      <Collapse open={abierto} id={`${id}-panel`}>
        <div className="pb-3 pl-6">{children}</div>
      </Collapse>
    </div>
  )
}

/* Lista de secciones plegables; con `single` abrir una cierra las demás. */
export function Accordion({
  items,
  single = false,
  defaultOpen = [],
  className = '',
}: {
  items: { id: string; title: ReactNode; content: ReactNode; right?: ReactNode }[]
  single?: boolean
  defaultOpen?: string[]
  className?: string
}) {
  const [abiertos, setAbiertos] = useState<Set<string>>(() => new Set(defaultOpen))
  return (
    <div className={className}>
      {items.map((it) => (
        <AccordionItem
          key={it.id}
          title={it.title}
          right={it.right}
          open={abiertos.has(it.id)}
          onOpenChange={(v) =>
            setAbiertos((s) => {
              const n = new Set(single ? [] : s)
              if (v) n.add(it.id)
              else n.delete(it.id)
              return n
            })
          }
        >
          {it.content}
        </AccordionItem>
      ))}
    </div>
  )
}
