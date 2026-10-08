import { useCallback, useEffect, useLayoutEffect, useRef, type CSSProperties, type KeyboardEvent, type MouseEvent, type ReactNode, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { esCapaSuperior, enfocables, useCapa } from './capas'
import { calcularPosicion, fueraDePantalla, rectDePunto, type Placement, type Rect } from './posicion'

/* Ancla: un elemento (botón, input, celda) o un punto de pantalla (clic derecho). */
export type AnclaPopover = RefObject<HTMLElement | null> | { x: number; y: number } | null

export type PopoverProps = {
  open: boolean
  onClose: () => void
  anchor: AnclaPopover
  placement?: Placement
  offset?: number
  /* Ancho fijo, o 'anchor' para medir como mínimo lo que el ancla. */
  width?: number | 'anchor'
  minWidth?: number
  maxHeight?: number
  flip?: boolean
  /* Al hacer scroll: recolocarse (y cerrarse si el ancla sale de pantalla) o cerrarse. */
  closeOnScroll?: 'reposition' | 'close'
  /* Foco al abrir: el primer elemento enfocable, el propio panel o ninguno
     (p. ej. el DatePicker deja el foco en su input). */
  initialFocus?: 'first' | 'panel' | 'none'
  returnFocus?: boolean
  /* Sin la caja por defecto (borde, sombra, padding): para flotantes con estilo propio. */
  unstyled?: boolean
  className?: string
  style?: CSSProperties
  children: ReactNode
  role?: string
  id?: string
  'aria-label'?: string
  'aria-labelledby'?: string
  'aria-multiselectable'?: boolean
  'aria-activedescendant'?: string
  tabIndex?: number
  onKeyDown?: (e: KeyboardEvent<HTMLDivElement>) => void
  onMouseDown?: (e: MouseEvent<HTMLDivElement>) => void
}

function esPunto(a: AnclaPopover): a is { x: number; y: number } {
  return !!a && 'x' in a && 'y' in a
}

function rectAncla(a: AnclaPopover): Rect | null {
  if (!a) return null
  if (esPunto(a)) return rectDePunto(a.x, a.y)
  const el = a.current
  return el ? el.getBoundingClientRect() : null
}

const CAJA = 'rounded-xl border border-line bg-pop p-[5px] shadow-pop dark:shadow-[0_18px_46px_rgba(0,0,0,.5)]'

/* Primitiva de todos los flotantes (Menu, Select, DatePicker, pickers…): se
   pinta en <body> para que no la recorte ningún contenedor con overflow. */
export default function Popover(props: PopoverProps) {
  if (!props.open) return null
  return <PanelAbierto {...props} />
}

function PanelAbierto({
  onClose,
  anchor,
  placement = 'bottom-start',
  offset = 4,
  width,
  minWidth = 150,
  maxHeight,
  flip = true,
  closeOnScroll = 'reposition',
  initialFocus = 'none',
  returnFocus = true,
  unstyled = false,
  className = '',
  style,
  children,
  ...resto
}: PopoverProps) {
  const panel = useRef<HTMLDivElement | null>(null)
  const cerrar = useRef(onClose)
  useEffect(() => {
    cerrar.current = onClose
  })
  const id = useCapa(true, { onEscape: () => cerrar.current() })

  // La posición se escribe directamente en el estilo del panel: cambia con cada
  // scroll y no merece un render de React cada vez.
  const colocar = useCallback(() => {
    const el = panel.current
    const r = rectAncla(anchor)
    if (!el || !r) return
    if (!esPunto(anchor) && fueraDePantalla(r, window.innerHeight, window.innerWidth)) {
      cerrar.current()
      return
    }
    if (width === 'anchor') el.style.minWidth = `${Math.max(minWidth, r.width)}px`
    const p = calcularPosicion({ ancla: r, ancho: el.offsetWidth, alto: el.scrollHeight, vw: window.innerWidth, vh: window.innerHeight, placement, offset, flip })
    el.style.left = `${p.left}px`
    el.style.top = p.top !== undefined ? `${p.top}px` : ''
    el.style.bottom = p.bottom !== undefined ? `${p.bottom}px` : ''
    el.style.maxHeight = `${Math.min(maxHeight ?? Infinity, p.maxHeight)}px`
    el.style.transformOrigin = p.lado === 'top' ? 'bottom' : 'top'
    el.style.visibility = ''
    el.dataset.lado = p.lado
  }, [anchor, placement, offset, flip, width, minWidth, maxHeight])

  useLayoutEffect(() => {
    colocar()
  }, [colocar])

  // El contenido puede cambiar de tamaño (resultados que llegan, filtro): se recoloca.
  useEffect(() => {
    const el = panel.current
    if (!el || typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver(() => colocar())
    ro.observe(el)
    return () => ro.disconnect()
  }, [colocar])

  useEffect(() => {
    let raf = 0
    const onScroll = (e: Event) => {
      if (panel.current && e.target instanceof Node && panel.current.contains(e.target)) return
      if (closeOnScroll === 'close') {
        cerrar.current()
        return
      }
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(colocar)
    }
    const onResize = () => {
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(colocar)
    }
    window.addEventListener('scroll', onScroll, true)
    window.addEventListener('resize', onResize)
    return () => {
      cancelAnimationFrame(raf)
      window.removeEventListener('scroll', onScroll, true)
      window.removeEventListener('resize', onResize)
    }
  }, [colocar, closeOnScroll])

  // Clic fuera: ignora el propio ancla (su botón ya abre/cierra) y los nodos que
  // el repintado ha desconectado (el clic que cambia el contenido del panel).
  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      const t = e.target
      if (!(t instanceof Node) || !t.isConnected) return
      if (!esCapaSuperior(id)) return
      if (panel.current?.contains(t)) return
      if (!esPunto(anchor) && anchor?.current?.contains(t)) return
      cerrar.current()
    }
    document.addEventListener('pointerdown', onDown, true)
    return () => document.removeEventListener('pointerdown', onDown, true)
  }, [anchor, id])

  // Foco inicial y devolución del foco al ancla al cerrar.
  useEffect(() => {
    const el = panel.current
    if (!el) return
    if (initialFocus === 'first') (enfocables(el)[0] ?? el).focus({ preventScroll: true })
    else if (initialFocus === 'panel') el.focus({ preventScroll: true })
    const anclaEl = !esPunto(anchor) ? anchor?.current : null
    return () => {
      if (!returnFocus || !anclaEl || !anclaEl.isConnected) return
      const ahora = document.activeElement
      if (!ahora || ahora === document.body || el.contains(ahora)) anclaEl.focus({ preventScroll: true })
    }
    // Solo al montar/desmontar el panel.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Antes de medir se pinta invisible para no dar un salto desde la esquina.
  const estilo: CSSProperties = {
    position: 'fixed',
    left: 0,
    top: 0,
    visibility: 'hidden',
    width: typeof width === 'number' ? width : undefined,
    minWidth,
    maxHeight,
    ...style,
  }

  return createPortal(
    <div
      ref={panel}
      // Los eventos de React suben por el árbol de componentes aunque el panel
      // esté en un portal: sin esto, elegir algo «clicaría» también la fila de debajo.
      onClick={(e) => e.stopPropagation()}
      className={`z-[1300] overflow-auto overscroll-contain motion-safe:animate-pop ${unstyled ? '' : CAJA} ${className}`}
      style={estilo}
      {...resto}
    >
      {children}
    </div>,
    document.body,
  )
}
