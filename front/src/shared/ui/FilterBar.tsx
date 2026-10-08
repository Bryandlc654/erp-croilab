import { useEffect, useState, type ReactNode, type Ref } from 'react'
import { Bookmark, Search, SlidersHorizontal, X } from 'lucide-react'
import Chip from './Chip'
import Collapse from './Collapse'
import Menu, { MenuItem, MenuLabel, MenuSeparator } from './Menu'

/* Buscador de listado (.cm-search): icono a la izquierda; avisa al dejar de
   teclear (`delay`) o al pulsar Enter. */
export function SearchBox({
  value,
  onChange,
  placeholder = 'Buscar…',
  delay = 250,
  className = '',
  ref,
  'aria-label': ariaLabel,
}: {
  value: string
  onChange: (v: string) => void
  placeholder?: string
  /* 0 = avisa en cada tecla. */
  delay?: number
  className?: string
  ref?: Ref<HTMLInputElement>
  'aria-label'?: string
}) {
  const [texto, setTexto] = useState(value)
  const [previo, setPrevio] = useState(value)
  // Si el valor cambia desde fuera (limpiar filtros), se refleja.
  if (value !== previo) {
    setPrevio(value)
    setTexto(value)
  }
  useEffect(() => {
    if (texto === value || delay === 0) return
    const t = setTimeout(() => onChange(texto), delay)
    return () => clearTimeout(t)
  }, [texto, value, delay, onChange])

  return (
    <label className={`flex min-w-[220px] max-w-[440px] flex-1 cursor-text items-center gap-2.5 rounded-[11px] border border-line bg-field px-[13px] py-[9px] transition-colors focus-within:border-ring hover:border-line-strong max-sm:max-w-none max-sm:min-w-0 ${className}`}>
      <Search className="size-4 shrink-0 text-label" aria-hidden="true" />
      <input
        ref={ref}
        type="search"
        value={texto}
        onChange={(e) => {
          setTexto(e.target.value)
          if (delay === 0) onChange(e.target.value)
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter') onChange(texto)
          if (e.key === 'Escape' && texto) {
            e.stopPropagation()
            setTexto('')
            onChange('')
          }
        }}
        placeholder={placeholder}
        aria-label={ariaLabel ?? placeholder}
        className="min-w-0 flex-1 bg-transparent text-[14px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px] [&::-webkit-search-cancel-button]:hidden"
      />
      {texto && (
        <button
          type="button"
          onClick={() => {
            setTexto('')
            onChange('')
          }}
          aria-label="Borrar búsqueda"
          className="flex size-5 shrink-0 items-center justify-center rounded-md text-label hover:bg-soft hover:text-ink"
        >
          <X className="size-3.5" />
        </button>
      )}
    </label>
  )
}

/* Panel plegable de filtros (.cm-panel): rejilla 4 → 2 → 1 columnas. */
export function FilterPanel({ open, onClear, children }: { open: boolean; onClear?: () => void; children: ReactNode }) {
  return (
    <Collapse open={open}>
      <div className="mb-4 rounded-[14px] border border-line bg-card px-[22px] py-5">
        <div className="grid grid-cols-4 gap-x-[18px] gap-y-4 max-[900px]:grid-cols-2 max-sm:grid-cols-1">{children}</div>
        {onClear && (
          <div className="mt-4 flex justify-end border-t border-line2 pt-3.5">
            <button type="button" onClick={onClear} className="text-[12.5px] font-semibold text-muted underline-offset-[3px] hover:text-ink-strong hover:underline">
              Limpiar filtros
            </button>
          </div>
        )}
      </div>
    </Collapse>
  )
}

export type FiltroActivo = { key: string; label: ReactNode; onRemove: () => void }

/* Chips de filtros aplicados con su ✕ y «Limpiar». */
export function ActiveFilterChips({ filters, onClearAll, className = '' }: { filters: FiltroActivo[]; onClearAll?: () => void; className?: string }) {
  if (filters.length === 0) return null
  return (
    <div className={`mb-4 flex flex-wrap items-center gap-1.5 ${className}`}>
      {filters.map((f) => (
        <Chip key={f.key} variant="filter" onRemove={f.onRemove} removeLabel="Quitar filtro">
          {f.label}
        </Chip>
      ))}
      {onClearAll && filters.length > 1 && (
        <button type="button" onClick={onClearAll} className="ml-1 text-[12px] font-semibold text-muted hover:text-ink-strong">
          Limpiar todo
        </button>
      )}
    </div>
  )
}

export type VistaGuardada = { id: string; label: string }

/* Vistas guardadas (.cm-dd): aplicar una o guardar los filtros actuales. */
export function SavedViewsMenu({ views, onApply, onSave, onDelete }: { views: VistaGuardada[]; onApply: (v: VistaGuardada) => void; onSave?: () => void; onDelete?: (v: VistaGuardada) => void }) {
  return (
    <Menu
      label="Vistas guardadas"
      width={240}
      trigger={(abierto) => (
        <span className={`inline-flex items-center gap-2 rounded-[11px] border bg-field px-3.5 py-[9px] text-[13px] font-semibold text-ink hover:bg-soft ${abierto ? 'border-line-strong' : 'border-line'}`}>
          <Bookmark className="size-[15px]" /> Vistas
        </span>
      )}
    >
      <MenuLabel>Vistas guardadas</MenuLabel>
      {views.length === 0 && <div className="px-3 py-2 text-[12.5px] text-muted">Aún no hay vistas.</div>}
      {views.map((v) => (
        <div key={v.id} className="group/vista flex items-center">
          <MenuItem onSelect={() => onApply(v)} className="flex-1">
            {v.label}
          </MenuItem>
          {onDelete && (
            <button
              type="button"
              onClick={() => onDelete(v)}
              aria-label={`Borrar la vista ${v.label}`}
              className="mr-1 flex size-6 shrink-0 items-center justify-center rounded-md text-label opacity-0 group-hover/vista:opacity-100 hover:bg-[#fde8e8] hover:text-[#c0392b] focus-visible:opacity-100"
            >
              <X className="size-3.5" />
            </button>
          )}
        </div>
      ))}
      {onSave && (
        <>
          <MenuSeparator />
          <MenuItem onSelect={onSave}>Guardar filtros actuales…</MenuItem>
        </>
      )}
    </Menu>
  )
}

/* Barra de filtros de un listado: buscador, botón «Filtros» con contador,
   píldoras rápidas y lo que se añada a la derecha (vistas, exportar…). El
   panel de filtros y los chips activos van debajo. */
export default function FilterBar({
  search,
  onSearch,
  searchPlaceholder,
  filtersCount = 0,
  panel,
  onClearFilters,
  quick,
  active,
  right,
  className = '',
}: {
  search?: string
  onSearch?: (v: string) => void
  searchPlaceholder?: string
  filtersCount?: number
  /* Contenido del panel de filtros (campos); sin él no hay botón «Filtros». */
  panel?: ReactNode
  onClearFilters?: () => void
  /* Píldoras rápidas: {value,label} y la elegida. */
  quick?: { items: { value: string; label: ReactNode }[]; value: string; onChange: (v: string) => void }
  active?: FiltroActivo[]
  right?: ReactNode
  className?: string
}) {
  const [abierto, setAbierto] = useState(false)
  return (
    <div className={className}>
      <div className="mb-3 flex flex-wrap items-center gap-2.5">
        {onSearch && <SearchBox value={search ?? ''} onChange={onSearch} placeholder={searchPlaceholder} />}
        {panel && (
          <button
            type="button"
            onClick={() => setAbierto((v) => !v)}
            aria-expanded={abierto}
            className={`inline-flex items-center gap-2 rounded-[11px] border bg-field px-3.5 py-[9px] text-[13px] font-semibold transition-colors hover:bg-soft ${
              filtersCount > 0 || abierto ? 'border-ink-strong text-ink-strong' : 'border-line text-ink'
            }`}
          >
            <SlidersHorizontal className="size-[15px]" aria-hidden="true" /> Filtros
            {filtersCount > 0 && <span className="rounded-full bg-ink-strong px-1.5 text-[10.5px] leading-[1.6] font-bold text-white dark:text-[#171717]">{filtersCount}</span>}
          </button>
        )}
        {right && <div className="ml-auto flex flex-wrap items-center gap-2.5">{right}</div>}
      </div>
      {quick && (
        <div className="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Filtros rápidos">
          {quick.items.map((q) => (
            <Chip key={q.value} variant="pick" on={q.value === quick.value} onClick={() => quick.onChange(q.value)}>
              {q.label}
            </Chip>
          ))}
        </div>
      )}
      {panel && (
        <FilterPanel open={abierto} onClear={onClearFilters}>
          {panel}
        </FilterPanel>
      )}
      {active && <ActiveFilterChips filters={active} onClearAll={onClearFilters} />}
    </div>
  )
}
