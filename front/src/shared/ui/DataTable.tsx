import { useMemo, useState, type CSSProperties, type ReactNode } from 'react'
import Checkbox from './Checkbox'
import { estadoSeleccion, ordenarFilas, siguienteOrden, type Orden, type ValorOrden } from './tabla'

export type Columna<T> = {
  key: string
  header: ReactNode
  /* Ancho CSS ('120px', '20%') o píxeles. */
  width?: string | number
  align?: 'left' | 'right' | 'center'
  sortable?: boolean
  /* Valor por el que se ordena (si no, la columna no se ordena en local). */
  sortValue?: (row: T) => ValorOrden
  /* Fija la columna a la izquierda al hacer scroll horizontal (solo la primera). */
  sticky?: boolean
  render: (row: T) => ReactNode
  className?: string
}

type Id = string | number

type Props<T> = {
  columns: Columna<T>[]
  rows: T[]
  getRowId: (row: T) => Id
  onRowClick?: (row: T) => void
  selectable?: boolean
  selected?: Id[]
  onSelect?: (ids: Id[]) => void
  /* Orden controlado; sin él la tabla lo guarda ella. */
  sort?: Orden
  onSort?: (o: Orden) => void
  defaultSort?: Orden
  /* El orden lo hace el servidor: la tabla solo pinta las flechas. */
  manualSort?: boolean
  stickyHeader?: boolean
  maxHeight?: number | string
  rowActions?: (row: T) => ReactNode
  /* Fila de 2 líneas para móvil (≤640px): sustituye a la tabla. */
  mobileRow?: (row: T) => ReactNode
  empty?: ReactNode
  loading?: boolean
  /* Pie: fila «+ Añadir…», «Cargar más»… */
  footer?: ReactNode
  rowClassName?: (row: T) => string
  'aria-label'?: string
  className?: string
}

const ALIGN = { left: 'text-left', right: 'text-right', center: 'text-center' }

/* Tabla de listados (table.cm del CRM, tablas de facturas/soporte): cabecera
   fija, orden por columna, selección con «todos» a medias, acciones al pasar
   el ratón y filas de dos líneas en el móvil. */
export default function DataTable<T>({
  columns,
  rows,
  getRowId,
  onRowClick,
  selectable = false,
  selected,
  onSelect,
  sort,
  onSort,
  defaultSort = null,
  manualSort = false,
  stickyHeader = true,
  maxHeight,
  rowActions,
  mobileRow,
  empty,
  loading = false,
  footer,
  rowClassName,
  'aria-label': ariaLabel,
  className = '',
}: Props<T>) {
  const [ordenPropio, setOrdenPropio] = useState<Orden>(defaultSort)
  const orden = sort !== undefined ? sort : ordenPropio
  const [selPropia, setSelPropia] = useState<Id[]>([])
  const seleccion = useMemo(() => new Set(selected ?? selPropia), [selected, selPropia])

  const filas = useMemo(() => {
    if (manualSort || !orden) return rows
    const col = columns.find((c) => c.key === orden.key)
    return ordenarFilas(rows, col?.sortValue, orden.dir)
  }, [rows, columns, orden, manualSort])

  const ids = filas.map(getRowId)
  const todos = estadoSeleccion(ids, seleccion)

  function cambiarSeleccion(nuevos: Id[]) {
    if (selected === undefined) setSelPropia(nuevos)
    onSelect?.(nuevos)
  }

  function ordenarPor(key: string) {
    const o = siguienteOrden(orden, key)
    if (sort === undefined) setOrdenPropio(o)
    onSort?.(o)
  }

  const conAcciones = !!rowActions
  const nCols = columns.length + (selectable ? 1 : 0) + (conAcciones ? 1 : 0)
  const estiloCaja: CSSProperties | undefined = maxHeight !== undefined ? { maxHeight } : undefined
  const stickyIzq = selectable ? 40 : 0

  const tabla = (
    <table className="w-full border-collapse text-[13px]" aria-label={ariaLabel} aria-busy={loading || undefined}>
      <thead>
        <tr>
          {selectable && (
            <th scope="col" className={`w-10 border-b border-line bg-head px-3 py-3.5 ${stickyHeader ? 'sticky top-0 z-[3]' : ''} left-0`}>
              <Checkbox
                checked={todos === 'all'}
                indeterminate={todos === 'some'}
                onChange={() => cambiarSeleccion(todos === 'all' ? [] : ids)}
                aria-label={todos === 'all' ? 'Quitar la selección' : 'Seleccionar todas las filas'}
              />
            </th>
          )}
          {columns.map((c, i) => {
            const activa = orden?.key === c.key
            const ordenable = c.sortable && (manualSort || !!c.sortValue)
            const fija = c.sticky && i === 0
            return (
              <th
                key={c.key}
                scope="col"
                aria-sort={activa ? (orden!.dir === 'asc' ? 'ascending' : 'descending') : ordenable ? 'none' : undefined}
                style={{ width: c.width, left: fija ? stickyIzq : undefined }}
                className={`border-b border-line bg-head px-3 py-3.5 text-[11px] font-[650] tracking-[.5px] whitespace-nowrap text-muted uppercase ${ALIGN[c.align ?? 'left']} ${
                  stickyHeader ? 'sticky top-0 z-[2]' : ''
                } ${fija ? 'z-[4] shadow-[1px_0_0_var(--c-line)]' : ''}`}
              >
                {ordenable ? (
                  <button type="button" onClick={() => ordenarPor(c.key)} className={`inline-flex items-center gap-1 uppercase hover:text-ink ${activa ? 'text-ink' : ''}`}>
                    {c.header}
                    <span aria-hidden="true" className={activa ? '' : 'opacity-0'}>
                      {activa && orden!.dir === 'desc' ? '↓' : '↑'}
                    </span>
                  </button>
                ) : (
                  c.header
                )}
              </th>
            )
          })}
          {conAcciones && (
            <th scope="col" className={`w-px border-b border-line bg-head px-3 py-3.5 ${stickyHeader ? 'sticky top-0 z-[2]' : ''}`}>
              <span className="sr-only">Acciones</span>
            </th>
          )}
        </tr>
      </thead>
      <tbody>
        {filas.map((row) => {
          const id = getRowId(row)
          const marcada = seleccion.has(id)
          return (
            <tr
              key={id}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              onKeyDown={
                onRowClick
                  ? (e) => {
                      if (e.key === 'Enter' && e.target === e.currentTarget) onRowClick(row)
                    }
                  : undefined
              }
              tabIndex={onRowClick ? 0 : undefined}
              aria-selected={selectable ? marcada : undefined}
              className={`group transition-colors ${onRowClick ? 'cursor-pointer' : ''} ${marcada ? 'bg-accent-soft' : 'bg-card hover:bg-[#fcfcfd] dark:hover:bg-soft'} ${rowClassName?.(row) ?? ''}`}
            >
              {selectable && (
                <td className="sticky left-0 z-[1] w-10 border-b border-line2 bg-inherit px-3 py-2.5" onClick={(e) => e.stopPropagation()}>
                  <Checkbox
                    checked={marcada}
                    onChange={(v) => cambiarSeleccion(v ? [...seleccion, id] : [...seleccion].filter((x) => x !== id))}
                    aria-label="Seleccionar fila"
                  />
                </td>
              )}
              {columns.map((c, i) => {
                const fija = c.sticky && i === 0
                return (
                  <td
                    key={c.key}
                    style={{ left: fija ? stickyIzq : undefined }}
                    className={`border-b border-line2 px-3 py-2.5 text-ink ${ALIGN[c.align ?? 'left']} ${
                      // La columna fija toma el fondo de la fila (seleccionada, hover) para tapar lo que pasa por debajo.
                      fija ? 'sticky z-[1] bg-inherit shadow-[1px_0_0_var(--c-line)]' : ''
                    } ${c.className ?? ''}`}
                  >
                    {c.render(row)}
                  </td>
                )
              })}
              {conAcciones && (
                <td className="w-px border-b border-line2 px-2 py-2.5 text-right whitespace-nowrap" onClick={(e) => e.stopPropagation()}>
                  <div className="inline-flex items-center gap-1 opacity-0 transition-opacity duration-[120ms] group-focus-within:opacity-100 group-hover:opacity-100 max-[760px]:opacity-100">
                    {rowActions!(row)}
                  </div>
                </td>
              )}
            </tr>
          )
        })}
        {filas.length === 0 && (
          <tr>
            <td colSpan={nCols} className="px-3 py-10 text-center text-[13px] text-muted">
              {loading ? 'Cargando…' : (empty ?? 'No hay nada que mostrar.')}
            </td>
          </tr>
        )}
      </tbody>
    </table>
  )

  return (
    <div className={`overflow-hidden rounded-2xl border border-line bg-card ${className}`}>
      <div className={`overflow-auto overscroll-contain ${mobileRow ? 'max-sm:hidden' : ''}`} style={estiloCaja}>
        {tabla}
      </div>
      {mobileRow && (
        <ul className="hidden divide-y divide-line2 max-sm:block">
          {filas.map((row) => (
            <li key={getRowId(row)}>
              {onRowClick ? (
                <button type="button" onClick={() => onRowClick(row)} className="block w-full px-3.5 py-[11px] text-left active:bg-soft">
                  {mobileRow(row)}
                </button>
              ) : (
                <div className="px-3.5 py-[11px]">{mobileRow(row)}</div>
              )}
            </li>
          ))}
          {filas.length === 0 && <li className="px-3.5 py-8 text-center text-[13px] text-muted">{loading ? 'Cargando…' : (empty ?? 'No hay nada que mostrar.')}</li>}
        </ul>
      )}
      {footer && <div className="border-t border-line2">{footer}</div>}
    </div>
  )
}
