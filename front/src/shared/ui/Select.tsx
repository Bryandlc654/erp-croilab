import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Check, ChevronDown, Search } from 'lucide-react'
import Popover from './Popover'
import { useCampo } from './campoContexto'

export type OpcionSelect<V extends string | number = string> = {
  value: V
  label: string
  /* Punto de color delante (estados, fases). */
  color?: string
  icon?: ReactNode
  hint?: string
  disabled?: boolean
}

type Props<V extends string | number> = {
  value: V | null | undefined
  onChange: (v: V) => void
  options: OpcionSelect<V>[]
  placeholder?: string
  size?: 'md' | 'sm'
  /* box: campo de formulario · mini: filtro compacto (.mini-sel) · inline: celda editable. */
  variant?: 'box' | 'mini' | 'inline'
  disabled?: boolean
  invalid?: boolean
  /* Buscador dentro del panel (listas largas). */
  searchable?: boolean
  searchPlaceholder?: string
  /* Para buscar en el servidor: recibe el texto tecleado (el filtro local sigue). */
  onSearchChange?: (q: string) => void
  emptyText?: string
  renderValue?: (o: OpcionSelect<V> | undefined) => ReactNode
  id?: string
  'aria-label'?: string
  className?: string
}

const TRIGGER: Record<NonNullable<Props<string>['variant']>, string> = {
  // Mismo alto que un TextInput para que casen en una fila de formulario.
  box: 'min-h-[43px] w-full gap-2 rounded-[10px] border bg-field px-3 py-[9px] text-[13.5px] leading-[1.2] max-sm:py-[11px] max-sm:text-[16px]',
  mini: 'w-auto max-w-[185px] gap-1.5 rounded-[9px] border bg-field px-[11px] py-[7px] text-[12.5px] font-medium hover:!border-accent max-sm:min-h-[38px]',
  inline: 'w-full gap-1.5 rounded-lg border border-transparent bg-transparent px-[9px] py-1.5 text-[13.5px] hover:bg-soft',
}

function normalizar(s: string) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
}

/* Select personalizado del ERP (.cs-trig/.cs-pop): disparador como un campo y
   lista flotante que se voltea, se recoloca al hacer scroll y se maneja con
   teclado (flechas, Inicio/Fin, Enter, Esc y salto por letra). */
export default function Select<V extends string | number>({
  value,
  onChange,
  options,
  placeholder = 'Selecciona…',
  size = 'md',
  variant = 'box',
  disabled = false,
  invalid,
  searchable = false,
  searchPlaceholder = 'Buscar…',
  onSearchChange,
  emptyText = 'Sin coincidencias',
  renderValue,
  id,
  'aria-label': ariaLabel,
  className = '',
}: Props<V>) {
  const campo = useCampo()
  const base = useId()
  const listaId = `${base}-lista`
  const boton = useRef<HTMLButtonElement>(null)
  const [abierto, setAbierto] = useState(false)
  const [q, setQ] = useState('')
  const [activo, setActivo] = useState(-1)

  const visibles = useMemo(() => {
    if (!searchable || !q.trim()) return options
    const n = normalizar(q.trim())
    return options.filter((o) => normalizar(o.label).includes(n))
  }, [options, q, searchable])

  const actual = options.find((o) => o.value === value)
  const inv = invalid ?? campo?.invalid ?? false

  function abrir() {
    if (disabled) return
    setQ('')
    const i = options.findIndex((o) => o.value === value)
    setActivo(i >= 0 ? i : options.findIndex((o) => !o.disabled))
    setAbierto(true)
  }

  function cerrar() {
    setAbierto(false)
    if (onSearchChange && q) onSearchChange('')
  }

  function elegir(o: OpcionSelect<V> | undefined) {
    if (!o || o.disabled) return
    cerrar()
    boton.current?.focus()
    if (o.value !== value) onChange(o.value)
  }

  // La opción activa siempre a la vista al moverse con el teclado.
  useEffect(() => {
    if (!abierto || activo < 0) return
    document.getElementById(`${base}-op-${activo}`)?.scrollIntoView?.({ block: 'nearest' })
  }, [abierto, activo, base])

  function mover(delta: number) {
    if (visibles.length === 0) return
    let i = activo
    for (let n = 0; n < visibles.length; n++) {
      i = (i + delta + visibles.length) % visibles.length
      if (!visibles[i].disabled) break
    }
    setActivo(i)
  }

  function onTeclaLista(e: KeyboardEvent) {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault()
        mover(1)
        return
      case 'ArrowUp':
        e.preventDefault()
        mover(-1)
        return
      case 'Home':
        e.preventDefault()
        setActivo(visibles.findIndex((o) => !o.disabled))
        return
      case 'End':
        e.preventDefault()
        for (let i = visibles.length - 1; i >= 0; i--) if (!visibles[i].disabled) return setActivo(i)
        return
      case 'Enter':
        e.preventDefault()
        elegir(visibles[activo])
        return
      case ' ':
        if (searchable) return
        e.preventDefault()
        elegir(visibles[activo])
        return
      case 'Tab':
        cerrar()
        return
    }
    if (!searchable && e.key.length === 1 && /\S/.test(e.key)) {
      const k = normalizar(e.key)
      const orden = [...visibles.keys()].map((n) => (activo + 1 + n) % visibles.length)
      const hit = orden.find((i) => !visibles[i].disabled && normalizar(visibles[i].label).startsWith(k))
      if (hit !== undefined) setActivo(hit)
    }
  }

  const idActivo = activo >= 0 && activo < visibles.length ? `${base}-op-${activo}` : undefined

  return (
    <>
      <button
        ref={boton}
        type="button"
        id={id ?? campo?.id}
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={abierto}
        aria-controls={abierto ? listaId : undefined}
        aria-label={ariaLabel}
        aria-invalid={inv || undefined}
        aria-describedby={campo?.ayudaId}
        disabled={disabled}
        onClick={(e) => {
          e.stopPropagation()
          if (abierto) cerrar()
          else abrir()
        }}
        onKeyDown={(e) => {
          if (abierto) return
          if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
            e.preventDefault()
            abrir()
          }
        }}
        className={`inline-flex items-center text-left text-ink transition-[border-color,box-shadow,background-color] duration-150 focus:outline-none disabled:cursor-not-allowed disabled:opacity-55 ${TRIGGER[variant]} ${
          variant === 'inline'
            ? abierto
              ? 'bg-soft'
              : ''
            : inv
              ? 'border-[#ef4444]'
              : abierto
                ? 'border-label shadow-[0_0_0_3px_rgba(17,19,24,.06)] dark:shadow-[0_0_0_3px_rgba(255,255,255,.06)]'
                : 'border-line hover:border-line-strong focus-visible:border-ring'
        } ${size === 'sm' && variant === 'box' ? '!min-h-0 !rounded-[9px] !px-[11px] !py-[7px] !text-[12.5px]' : ''} ${className}`}
      >
        <span className="flex min-w-0 flex-1 items-center gap-2 truncate">
          {renderValue ? (
            renderValue(actual)
          ) : actual ? (
            <>
              {actual.color && <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: actual.color }} aria-hidden="true" />}
              {actual.icon && <span className="flex shrink-0 text-label [&>svg]:size-4">{actual.icon}</span>}
              <span className="truncate">{actual.label}</span>
            </>
          ) : (
            <span className="truncate text-label">{placeholder}</span>
          )}
        </span>
        <ChevronDown className={`size-[15px] shrink-0 text-label transition-transform duration-150 ${abierto ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>

      <Popover
        open={abierto}
        onClose={cerrar}
        anchor={boton}
        width="anchor"
        minWidth={variant === 'mini' ? 160 : 150}
        maxHeight={searchable ? 340 : 288}
        initialFocus={searchable ? 'first' : 'panel'}
        tabIndex={searchable ? undefined : -1}
        className="flex flex-col outline-none"
        onKeyDown={searchable ? undefined : onTeclaLista}
        role={searchable ? undefined : 'listbox'}
        id={searchable ? undefined : listaId}
        aria-label={searchable ? undefined : ariaLabel}
        aria-activedescendant={searchable ? undefined : idActivo}
      >
        {searchable && (
          <div className="relative mb-1 shrink-0">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-label" aria-hidden="true" />
            <input
              type="text"
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setActivo(0)
                onSearchChange?.(e.target.value)
              }}
              onKeyDown={onTeclaLista}
              placeholder={searchPlaceholder}
              aria-label={searchPlaceholder}
              role="combobox"
              aria-expanded="true"
              aria-controls={listaId}
              aria-activedescendant={idActivo}
              aria-autocomplete="list"
              className="w-full rounded-lg bg-soft py-2 pr-2.5 pl-8 text-[13px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px]"
            />
          </div>
        )}
        <div role={searchable ? 'listbox' : undefined} id={searchable ? listaId : undefined} aria-label={searchable ? ariaLabel : undefined} className="min-h-0 flex-1 overflow-y-auto">
          {visibles.length === 0 && <div className="px-[11px] py-2 text-[12.5px] text-muted">{emptyText}</div>}
          {visibles.map((o, i) => {
            const sel = o.value === value
            return (
              <div
                key={String(o.value)}
                id={`${base}-op-${i}`}
                role="option"
                aria-selected={sel}
                aria-disabled={o.disabled || undefined}
                onMouseEnter={() => !o.disabled && setActivo(i)}
                // El foco se queda en el panel/buscador: si no, el clic lo perdería.
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => elegir(o)}
                className={`flex cursor-pointer items-center gap-2 rounded-lg px-[11px] py-2 text-[13px] max-sm:px-[13px] max-sm:py-3 max-sm:text-[14px] ${
                  o.disabled
                    ? 'cursor-default opacity-50'
                    : sel
                      ? 'bg-accent-soft font-semibold text-accent'
                      : i === activo
                        ? 'bg-soft text-ink'
                        : 'text-[#4c515b] dark:text-nav-ink'
                } ${sel && i === activo ? 'ring-1 ring-line-strong ring-inset' : ''}`}
              >
                {o.color && <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: o.color }} aria-hidden="true" />}
                {o.icon && <span className="flex shrink-0 text-label [&>svg]:size-4">{o.icon}</span>}
                <span className="min-w-0 flex-1 truncate">{o.label}</span>
                {o.hint && <span className="shrink-0 text-[11.5px] font-normal text-muted">{o.hint}</span>}
                {sel && <Check className="size-3.5 shrink-0" aria-hidden="true" />}
              </div>
            )
          })}
        </div>
      </Popover>
    </>
  )
}
