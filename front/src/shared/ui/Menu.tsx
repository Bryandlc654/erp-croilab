import { useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Check } from 'lucide-react'
import Popover, { type AnclaPopover } from './Popover'
import { MenuContext, useMenuActual } from './menuContexto'
import type { Placement } from './posicion'

const ITEMS = '[role=menuitem]:not([disabled]),[role=menuitemcheckbox]:not([disabled]),[role=menuitemradio]:not([disabled])'

/* Flechas, Inicio y Fin mueven el foco entre las opciones (como un menú nativo). */
function navegar(e: KeyboardEvent<HTMLDivElement>, cerrar: () => void) {
  const items = Array.from(e.currentTarget.querySelectorAll<HTMLElement>(ITEMS))
  if (items.length === 0) return
  const i = items.indexOf(document.activeElement as HTMLElement)
  let j: number | null = null
  if (e.key === 'ArrowDown') j = i < 0 ? 0 : (i + 1) % items.length
  else if (e.key === 'ArrowUp') j = i < 0 ? items.length - 1 : (i - 1 + items.length) % items.length
  else if (e.key === 'Home') j = 0
  else if (e.key === 'End') j = items.length - 1
  else if (e.key === 'Tab') {
    cerrar()
    return
  } else if (e.key.length === 1 && /\S/.test(e.key)) {
    // Salto por la primera letra.
    const k = e.key.toLowerCase()
    const orden = [...items.slice(i + 1), ...items.slice(0, i + 1)]
    const hit = orden.find((el) => (el.textContent ?? '').trim().toLowerCase().startsWith(k))
    if (hit) j = items.indexOf(hit)
  }
  if (j === null) return
  e.preventDefault()
  items[j].focus()
}

export type MenuPanelProps = {
  open: boolean
  onClose: () => void
  anchor: AnclaPopover
  placement?: Placement
  width?: number | 'anchor'
  minWidth?: number
  className?: string
  label?: string
  children: ReactNode
}

/* El panel del menú sin disparador: para menús contextuales (useContextMenu)
   o abiertos desde otro sitio. */
export function MenuPanel({ open, onClose, anchor, placement, width, minWidth = 184, className = '', label, children }: MenuPanelProps) {
  const ctx = useMemo(() => ({ cerrar: onClose }), [onClose])
  return (
    <Popover
      open={open}
      onClose={onClose}
      anchor={anchor}
      placement={placement}
      width={width}
      minWidth={minWidth}
      role="menu"
      aria-label={label}
      tabIndex={-1}
      initialFocus="panel"
      className={`outline-none ${className}`}
      onKeyDown={(e) => navegar(e, onClose)}
    >
      <MenuContext.Provider value={ctx}>{children}</MenuContext.Provider>
    </Popover>
  )
}

/* Desplegable con disparador: `trigger(abierto)` pinta el botón y `children`
   recibe `cerrar` (o son MenuItem, que cierran solos al elegir). */
export default function Menu({
  trigger,
  children,
  align = 'left',
  placement,
  disabled = false,
  className = '',
  panelClassName = '',
  label,
  width,
}: {
  trigger: (abierto: boolean) => ReactNode
  children: ReactNode | ((cerrar: () => void) => ReactNode)
  align?: 'left' | 'right'
  placement?: Placement
  disabled?: boolean
  className?: string
  panelClassName?: string
  label?: string
  width?: number | 'anchor'
}) {
  const [abierto, setAbierto] = useState(false)
  const boton = useRef<HTMLButtonElement>(null)
  const cerrar = useMemo(() => () => setAbierto(false), [])

  return (
    // El panel va en un portal, pero los eventos de React siguen subiendo por
    // este div: se paran aquí para que elegir algo no abra la fila de debajo.
    <div className={`relative ${className}`} onClick={(e) => e.stopPropagation()}>
      <button
        ref={boton}
        type="button"
        disabled={disabled}
        onClick={() => setAbierto((v) => !v)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown' && !abierto) {
            e.preventDefault()
            setAbierto(true)
          }
        }}
        aria-haspopup="menu"
        aria-expanded={abierto}
        aria-label={label}
        className="flex w-full items-center rounded-[7px] text-left disabled:cursor-default"
      >
        {trigger(abierto)}
      </button>
      <MenuPanel
        open={abierto}
        onClose={cerrar}
        anchor={boton}
        placement={placement ?? (align === 'right' ? 'bottom-end' : 'bottom-start')}
        width={width}
        className={panelClassName}
        label={label}
      >
        {typeof children === 'function' ? children(cerrar) : children}
      </MenuPanel>
    </div>
  )
}

export function MenuItem({
  onClick,
  onSelect,
  children,
  on = false,
  selected,
  icon,
  danger = false,
  shortcut,
  color,
  disabled = false,
  keepOpen = false,
  check = false,
  className = '',
}: {
  onClick?: () => void
  onSelect?: () => void
  children: ReactNode
  /* Opción actual (fondo suave). `selected` es sinónimo. */
  on?: boolean
  selected?: boolean
  icon?: ReactNode
  danger?: boolean
  shortcut?: string
  /* Cuadradito de color delante (etiquetas, fases). */
  color?: string
  disabled?: boolean
  /* No cerrar al elegir (selección múltiple). */
  keepOpen?: boolean
  /* Marca ✓ a la derecha cuando está seleccionada. */
  check?: boolean
  className?: string
}) {
  const menu = useMenuActual()
  const activo = selected ?? on
  return (
    <button
      type="button"
      role="menuitem"
      disabled={disabled}
      onClick={() => {
        const elegir = onSelect ?? onClick
        elegir?.()
        if (!keepOpen) menu?.cerrar()
      }}
      className={`flex w-full items-center gap-[9px] rounded-lg px-3 py-2 text-left text-[13px] font-medium outline-none disabled:cursor-default disabled:opacity-50 max-sm:py-2.5 max-sm:text-[14px] ${
        danger
          ? 'text-[#c0392b] hover:bg-[#fde8e8] focus-visible:bg-[#fde8e8] dark:text-danger dark:hover:bg-danger-bg dark:focus-visible:bg-danger-bg'
          : `hover:bg-soft hover:text-ink focus-visible:bg-soft focus-visible:text-ink ${activo ? 'bg-soft text-ink' : 'text-[#4c515b] dark:text-nav-ink'}`
      } ${className}`}
    >
      {color && <span className="size-[9px] shrink-0 rounded-[3px]" style={{ backgroundColor: color }} aria-hidden="true" />}
      {icon && <span className="flex shrink-0 text-label [&>svg]:size-4">{icon}</span>}
      {children}
      {shortcut && <kbd className="ml-auto pl-3 font-sans text-[11px] font-semibold text-label">{shortcut}</kbd>}
      {check && activo && <Check className="ml-auto size-3.5 shrink-0" aria-hidden="true" />}
    </button>
  )
}

export function MenuSeparator() {
  return <div role="separator" className="mx-1.5 my-1 h-px bg-line" />
}

export function MenuLabel({ children }: { children: ReactNode }) {
  return <div className="px-2.5 pt-[7px] pb-[5px] text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">{children}</div>
}
