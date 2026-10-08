import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { CalendarDays } from 'lucide-react'
import { RowGrip } from '../SortableList'
import { useSortable } from '../../lib/useSortable'
import { huecoInsercion } from '../../lib/ordenar'
import { columnaEnX, esMovimiento, moverTarjeta, pasoTeclado, ubicar, type IdKanban, type PosKanban } from '../../lib/kanban'

export type KanbanColumnData<T> = {
  id: IdKanban
  title: string
  /* Color de la fase (punto de la cabecera). */
  color?: string
  items: T[]
  /* Pie «Total»: importe ya formateado (p. ej. eur0(suma)). */
  total?: ReactNode
}

export type KanbanBoardProps<T> = {
  columns: KanbanColumnData<T>[]
  getItemId: (item: T) => IdKanban
  /* Contenido de la tarjeta (normalmente <KanbanCard …/>). */
  renderCard: (item: T, estado: { dragging: boolean }) => ReactNode
  /* Soltar: id, columna destino y posición en ella (contada sin la tarjeta). */
  onMove?: (itemId: IdKanban, toColumnId: IdKanban, index: number) => void
  /* Reordenar columnas arrastrando su asa (solo el dueño, en el CRM). */
  onReorderColumns?: (ids: IdKanban[]) => void
  onCardClick?: (item: T) => void
  /* Texto de la tarjeta para lectores de pantalla y avisos. */
  cardLabel?: (item: T) => string
  /* Extra a la derecha de la cabecera de cada columna (botón «+»). */
  columnAction?: (col: KanbanColumnData<T>) => ReactNode
  emptyText?: ReactNode
  className?: string
}

type Inicio = { id: IdKanban; x: number; y: number; tactil: boolean; activo: boolean; temporizador: ReturnType<typeof setTimeout> | null; caja: DOMRect }
type Arrastre = { id: IdKanban; x: number; y: number; offX: number; offY: number; ancho: number; destino: PosKanban | null }

const UMBRAL = 4
const PULSACION_LARGA = 300
const BORDE_SCROLL = 48

/* Tablero kanban (embudo del CRM, negocio.php): columnas de 264 px con scroll
   propio, tarjetas que se arrastran entre columnas y dentro de una, columnas
   reordenables, y teclado: Espacio coge la tarjeta, flechas la mueven,
   Espacio/Intro la suelta y Esc cancela. */
export default function KanbanBoard<T>({
  columns,
  getItemId,
  renderCard,
  onMove,
  onReorderColumns,
  onCardClick,
  cardLabel = () => 'Tarjeta',
  columnAction,
  emptyText = '—',
  className = '',
}: KanbanBoardProps<T>) {
  const tablero = useRef<HTMLDivElement>(null)
  const cols = useRef(new Map<IdKanban, HTMLElement>())
  const cuerpos = useRef(new Map<IdKanban, HTMLElement>())
  const tarjetas = useRef(new Map<IdKanban, HTMLElement>())
  const inicio = useRef<Inicio | null>(null)
  const [arrastre, setArrastre] = useState<Arrastre | null>(null)
  const ultimo = useRef<Arrastre | null>(null)
  // Teclado: tarjeta cogida y su posición provisional.
  const [cogida, setCogida] = useState<{ id: IdKanban; pos: PosKanban } | null>(null)
  const [aviso, setAviso] = useState('')
  const datos = useRef({ columns, getItemId, onMove })
  useEffect(() => {
    datos.current = { columns, getItemId, onMove }
  })

  const colSort = useSortable({ ids: columns.map((c) => c.id), onReorder: (ids) => onReorderColumns?.(ids), axis: 'x', disabled: !onReorderColumns })

  // Vista: con una tarjeta cogida por teclado, ya colocada donde iría.
  const vista = cogida ? moverTarjeta(columns, getItemId, cogida.id, columns[cogida.pos.col]?.id, cogida.pos.idx) : columns
  const itemDe = (id: IdKanban) => {
    for (const c of columns) for (const it of c.items) if (getItemId(it) === id) return it
    return undefined
  }

  useEffect(() => {
    if (cogida) tarjetas.current.get(cogida.id)?.focus({ preventScroll: false })
  }, [cogida])

  // Arrastre con puntero (ratón, dedo con pulsación larga, lápiz).
  useEffect(() => {
    const calcular = (x: number, y: number, id: IdKanban): PosKanban | null => {
      const cs = datos.current.columns
      const cajas = cs.map((c) => cols.current.get(c.id)?.getBoundingClientRect() ?? new DOMRect())
      const col = columnaEnX(cajas, x)
      if (col < 0) return null
      const g = datos.current.getItemId
      const otras = cs[col].items.filter((it) => g(it) !== id)
      const rects = otras.map((it) => tarjetas.current.get(g(it))?.getBoundingClientRect() ?? new DOMRect())
      return { col, idx: huecoInsercion(rects, { x, y }, 'y') }
    }
    const autoScroll = (x: number, y: number, col: number | undefined) => {
      const t = tablero.current
      if (t) {
        const r = t.getBoundingClientRect()
        if (x < r.left + BORDE_SCROLL) t.scrollLeft -= 14
        else if (x > r.right - BORDE_SCROLL) t.scrollLeft += 14
      }
      const c = col !== undefined ? datos.current.columns[col] : undefined
      const cuerpo = c ? cuerpos.current.get(c.id) : undefined
      if (cuerpo) {
        const r = cuerpo.getBoundingClientRect()
        if (y < r.top + 36) cuerpo.scrollTop -= 12
        else if (y > r.bottom - 36) cuerpo.scrollTop += 12
      }
    }
    const activar = (ini: Inicio, x: number, y: number) => {
      ini.activo = true
      const a: Arrastre = { id: ini.id, x, y, offX: ini.x - ini.caja.left, offY: ini.y - ini.caja.top, ancho: ini.caja.width, destino: calcular(x, y, ini.id) }
      ultimo.current = a
      setArrastre(a)
    }
    const mover = (e: PointerEvent) => {
      const ini = inicio.current
      if (!ini) return
      if (!ini.activo) {
        if (Math.hypot(e.clientX - ini.x, e.clientY - ini.y) < UMBRAL) return
        // Con el dedo, moverse antes de la pulsación larga es hacer scroll.
        if (ini.tactil) {
          if (ini.temporizador) clearTimeout(ini.temporizador)
          inicio.current = null
          return
        }
        activar(ini, e.clientX, e.clientY)
      }
      e.preventDefault()
      const destino = calcular(e.clientX, e.clientY, ini.id)
      autoScroll(e.clientX, e.clientY, destino?.col)
      const a = { ...(ultimo.current as Arrastre), x: e.clientX, y: e.clientY, destino }
      ultimo.current = a
      setArrastre(a)
    }
    const terminar = (soltar: boolean) => {
      const ini = inicio.current
      const a = ultimo.current
      if (ini?.temporizador) clearTimeout(ini.temporizador)
      inicio.current = null
      ultimo.current = null
      setArrastre(null)
      if (!ini?.activo || !a) return
      // El clic que sigue a soltar no debe abrir la tarjeta.
      const tragar = (ev: Event) => {
        ev.stopPropagation()
        ev.preventDefault()
      }
      window.addEventListener('click', tragar, { capture: true, once: true })
      setTimeout(() => window.removeEventListener('click', tragar, { capture: true }), 50)
      const { columns: cs, getItemId: g, onMove: fn } = datos.current
      if (!soltar || !a.destino || !fn) return
      const destino = cs[a.destino.col]
      if (destino && esMovimiento(cs, g, a.id, destino.id, a.destino.idx)) fn(a.id, destino.id, a.destino.idx)
    }
    const arriba = () => terminar(true)
    const cancelar = () => terminar(false)
    const esc = (e: globalThis.KeyboardEvent) => e.key === 'Escape' && inicio.current?.activo && terminar(false)
    // En táctil, una vez cogida, el dedo ya no desplaza la página.
    const tocar = (e: TouchEvent) => inicio.current?.activo && e.cancelable && e.preventDefault()
    window.addEventListener('pointermove', mover, { passive: false })
    window.addEventListener('pointerup', arriba)
    window.addEventListener('pointercancel', cancelar)
    window.addEventListener('keydown', esc)
    window.addEventListener('touchmove', tocar, { passive: false })
    return () => {
      window.removeEventListener('pointermove', mover)
      window.removeEventListener('pointerup', arriba)
      window.removeEventListener('pointercancel', cancelar)
      window.removeEventListener('keydown', esc)
      window.removeEventListener('touchmove', tocar)
    }
  }, [])

  function empezar(e: ReactPointerEvent<HTMLElement>, id: IdKanban) {
    if (!onMove || e.button !== 0 || cogida) return
    // Los botones y enlaces de dentro de la tarjeta siguen funcionando.
    if ((e.target as HTMLElement).closest('button,a,input,select,textarea,[data-no-drag]')) return
    const caja = e.currentTarget.getBoundingClientRect()
    const tactil = e.pointerType === 'touch'
    const ini: Inicio = { id, x: e.clientX, y: e.clientY, tactil, activo: false, temporizador: null, caja }
    if (tactil) {
      ini.temporizador = setTimeout(() => {
        if (inicio.current === ini) {
          navigator.vibrate?.(15)
          const c = ini
          c.activo = true
          const a: Arrastre = { id, x: c.x, y: c.y, offX: c.x - caja.left, offY: c.y - caja.top, ancho: caja.width, destino: null }
          ultimo.current = a
          setArrastre(a)
        }
      }, PULSACION_LARGA)
    }
    inicio.current = ini
  }

  function teclado(e: KeyboardEvent<HTMLElement>, item: T) {
    const id = getItemId(item)
    if (cogida?.id === id) {
      const longitudes = vista.map((c) => c.items.length)
      if (e.key.startsWith('Arrow')) {
        e.preventDefault()
        const pos = pasoTeclado(longitudes, cogida.pos, e.key)
        setCogida({ id, pos })
        setAviso(`${vista[pos.col]?.title ?? ''}, posición ${pos.idx + 1}`)
      } else if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault()
        const destino = columns[cogida.pos.col]
        setCogida(null)
        if (destino && onMove && esMovimiento(columns, getItemId, id, destino.id, cogida.pos.idx)) onMove(id, destino.id, cogida.pos.idx)
        setAviso(`${cardLabel(item)} soltada en ${destino?.title ?? ''}.`)
      } else if (e.key === 'Escape' || e.key === 'Tab') {
        if (e.key === 'Escape') e.preventDefault()
        setCogida(null)
        setAviso('Movimiento cancelado.')
      }
      return
    }
    if (e.key === ' ' && onMove) {
      e.preventDefault()
      const pos = ubicar(columns, getItemId, id)
      if (!pos) return
      setCogida({ id, pos })
      setAviso(`${cardLabel(item)} cogida. Flechas para moverla, Espacio para soltarla, Esc para cancelar.`)
    } else if (e.key === 'Enter' && onCardClick) {
      e.preventDefault()
      onCardClick(item)
    }
  }

  const fantasma = arrastre ? itemDe(arrastre.id) : undefined

  return (
    <>
      <div ref={tablero} className={`flex items-start gap-3.5 overflow-x-auto overscroll-x-contain pb-3.5 max-sm:snap-x max-sm:snap-mandatory ${className}`}>
        {vista.map((col, ci) => {
          const destino = arrastre?.destino?.col === ci
          const marca = colSort.marca(col.id)
          const hp = colSort.handleProps(col.id)
          return (
            <KanbanColumn
              key={col.id}
              ref={(el) => {
                colSort.itemRef(col.id)(el)
                if (el) cols.current.set(col.id, el)
                else cols.current.delete(col.id)
              }}
              bodyRef={(el) => {
                if (el) cuerpos.current.set(col.id, el)
                else cuerpos.current.delete(col.id)
              }}
              title={col.title}
              color={col.color}
              count={col.items.length}
              total={col.total}
              action={columnAction?.(col)}
              grip={onReorderColumns ? <RowGrip {...hp} className="!opacity-60 hover:!opacity-100" /> : null}
              highlight={destino}
              className={`${colSort.arrastrando === col.id ? 'opacity-35' : ''} ${marca === 'antes' ? 'shadow-[inset_3px_0_0_#3b82f6]' : marca === 'despues' ? 'shadow-[inset_-3px_0_0_#3b82f6]' : ''}`}
            >
              {col.items.length === 0 && !(destino && arrastre) ? <div className="py-3 text-center text-[12.5px] text-label">{emptyText}</div> : null}
              {(() => {
                const otras = arrastre ? col.items.filter((it) => getItemId(it) !== arrastre.id) : col.items
                const out: ReactNode[] = []
                col.items.forEach((it) => {
                  const id = getItemId(it)
                  const idxSin = otras.findIndex((o) => getItemId(o) === id)
                  if (destino && arrastre && idxSin === arrastre.destino?.idx) out.push(<Marca key="marca" />)
                  const arrastrando = arrastre?.id === id
                  const enMano = cogida?.id === id
                  out.push(
                    <div
                      key={id}
                      ref={(el) => {
                        if (el) tarjetas.current.set(id, el)
                        else tarjetas.current.delete(id)
                      }}
                      role="button"
                      tabIndex={0}
                      aria-roledescription={onMove ? 'tarjeta arrastrable' : undefined}
                      aria-pressed={onMove ? enMano : undefined}
                      aria-label={cardLabel(it)}
                      onPointerDown={(e) => empezar(e, id)}
                      onClick={() => onCardClick?.(it)}
                      onKeyDown={(e) => teclado(e, it)}
                      // Al cambiar de columna la tarjeta se vuelve a montar; solo se cancela si el foco se va a otro sitio.
                      onBlur={(e) => enMano && e.relatedTarget !== null && setCogida(null)}
                      style={{ touchAction: arrastre ? 'none' : 'manipulation' }}
                      className={`group/card relative rounded-[11px] border border-line bg-card p-[13px] text-left shadow-[0_1px_2px_rgba(16,19,24,.03)] transition-[border-color,box-shadow,opacity] select-none hover:border-line-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-[#3b82f6] ${
                        onMove ? 'cursor-grab active:cursor-grabbing' : onCardClick ? 'cursor-pointer' : ''
                      } ${arrastrando ? 'opacity-40' : ''} ${enMano ? 'shadow-[0_10px_26px_-8px_rgba(16,19,24,.35)] ring-2 ring-[#3b82f6]' : ''}`}
                    >
                      {renderCard(it, { dragging: arrastrando })}
                    </div>,
                  )
                })
                if (destino && arrastre && arrastre.destino && arrastre.destino.idx >= otras.length) out.push(<Marca key="marca" />)
                return out
              })()}
            </KanbanColumn>
          )
        })}
      </div>
      <div className="sr-only" aria-live="assertive">
        {aviso}
      </div>
      {arrastre &&
        fantasma !== undefined &&
        createPortal(
          <div
            className="pointer-events-none fixed top-0 left-0 z-[1400] rotate-[1.5deg] rounded-[11px] border border-line bg-card p-[13px] text-left shadow-[0_18px_40px_-10px_rgba(16,19,24,.35)]"
            style={{ width: arrastre.ancho, transform: `translate(${arrastre.x - arrastre.offX}px, ${arrastre.y - arrastre.offY}px) rotate(1.5deg)` }}
          >
            {renderCard(fantasma, { dragging: true })}
          </div>,
          document.body,
        )}
    </>
  )
}

function Marca() {
  return <div className="h-[3px] shrink-0 rounded-full bg-[#3b82f6]" aria-hidden="true" />
}

/* Columna del tablero (.ng-col): cabecera con punto de color, nombre y
   contador; cuerpo con scroll propio; pie «Total». */
export function KanbanColumn({
  title,
  color,
  count,
  total,
  action,
  grip,
  highlight = false,
  children,
  className = '',
  ref,
  bodyRef,
}: {
  title: string
  color?: string
  count?: number
  total?: ReactNode
  action?: ReactNode
  grip?: ReactNode
  /* Destino del arrastre: contorno discontinuo azul. */
  highlight?: boolean
  children?: ReactNode
  className?: string
  ref?: (el: HTMLElement | null) => void
  bodyRef?: (el: HTMLElement | null) => void
}) {
  return (
    <section
      ref={ref}
      aria-label={title}
      className={`flex max-h-[calc(100vh-250px)] min-h-[140px] shrink-0 grow-0 basis-[264px] flex-col rounded-[14px] border border-line bg-soft transition-[background-color,outline-color] max-sm:basis-[82vw] max-sm:snap-start ${
        highlight ? 'bg-[#eef4ff] outline-2 -outline-offset-2 outline-[#2f6df6] outline-dashed dark:bg-[rgba(47,109,246,.12)]' : ''
      } ${className}`}
    >
      <header className="flex items-center gap-2 px-3.5 pt-3.5 pb-2.5">
        {grip}
        <span className="size-[9px] shrink-0 rounded-full" style={{ backgroundColor: color ?? '#98a2b3' }} aria-hidden="true" />
        <h3 className="min-w-0 flex-1 truncate text-[13px] font-[650] text-ink-strong">{title}</h3>
        {count !== undefined && <span className="rounded-full border border-line bg-card px-2 py-px text-[11.5px] font-semibold text-muted tabular-nums">{count}</span>}
        {action}
      </header>
      <div ref={bodyRef} className="flex min-h-[40px] flex-1 flex-col gap-2 overflow-y-auto overscroll-contain px-2.5 pt-0.5 pb-2.5">
        {children}
      </div>
      {total !== undefined && (
        <footer className="flex items-center justify-between border-t border-line px-3.5 py-[11px] text-[11.5px] text-muted">
          <span>Total</span>
          <b className="font-[650] text-ink-strong tabular-nums">{total}</b>
        </footer>
      )}
    </section>
  )
}

/* Contenido estándar de una tarjeta del embudo (.ng-card). */
export function KanbanCard({
  title,
  subtitle,
  value,
  chips,
  avatar,
  date,
  dateTone,
  menu,
}: {
  title: ReactNode
  /* «empresa · sector». */
  subtitle?: ReactNode
  /* Importe ya formateado (verde). */
  value?: ReactNode
  /* Chip de servicio, etiquetas… */
  chips?: ReactNode
  avatar?: ReactNode
  /* Fecha de cierre ya formateada. */
  date?: ReactNode
  dateTone?: 'late' | 'soon' | null
  /* «⋯» visible al pasar el ratón (se marca para no arrastrar). */
  menu?: ReactNode
}) {
  return (
    <>
      {menu && (
        <div data-no-drag className="absolute top-2 right-2 opacity-0 transition-opacity group-focus-within/card:opacity-100 group-hover/card:opacity-100 max-sm:opacity-100">
          {menu}
        </div>
      )}
      <div className={`text-[13.5px] leading-snug font-[650] text-ink-strong ${menu ? 'pr-6' : ''}`}>{title}</div>
      {subtitle && <div className="mt-0.5 truncate text-[11.5px] text-muted">{subtitle}</div>}
      {(value || chips || avatar || date) && (
        <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
          {value && <span className="text-[13px] font-[750] text-ok tabular-nums">{value}</span>}
          {chips}
          <span className="flex-1" />
          {date && (
            <span className={`inline-flex items-center gap-1 text-[11px] ${dateTone === 'late' ? 'font-semibold text-[#e5484d]' : dateTone === 'soon' ? 'font-semibold text-[#e0a000]' : 'text-muted'}`}>
              <CalendarDays className="size-3" aria-hidden="true" />
              {date}
            </span>
          )}
          {avatar}
        </div>
      )}
    </>
  )
}
