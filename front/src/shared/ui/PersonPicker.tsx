import { useId, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Check, Search, UserPlus, X } from 'lucide-react'
import Avatar from './Avatar'
import AvatarStack, { type PersonaAvatar } from './AvatarStack'
import Popover from './Popover'

type Persona = PersonaAvatar & { id: number }

type Comunes = {
  people: Persona[]
  /* Opción «Sin asignar» / «Quitar» al principio. */
  allowNone?: boolean
  noneLabel?: string
  placeholder?: string
  disabled?: boolean
  /* Para marcar «tú» junto a tu nombre. */
  meId?: number
  label: string
  /* Disparador propio; por defecto avatares + nombre o «Asignar». */
  trigger?: (elegidos: Persona[], abierto: boolean) => ReactNode
  className?: string
}
type Uno = Comunes & { multiple?: false; value: number | null; onChange: (v: number | null) => void }
type Varios = Comunes & { multiple: true; value: number[]; onChange: (v: number[]) => void }

function normalizar(s: string) {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
}

/* Selector de persona(s) (responsable, asignados): lista con avatar y ✓; con
   `multiple` no se cierra al elegir. Con más de 8 personas lleva buscador. */
export default function PersonPicker(props: Uno | Varios) {
  const { people, allowNone = false, noneLabel = 'Sin asignar', placeholder = 'Asignar', disabled = false, meId, label, trigger, className = '' } = props
  const boton = useRef<HTMLButtonElement>(null)
  const base = useId()
  const [abierto, setAbierto] = useState(false)
  const [q, setQ] = useState('')
  const [activo, setActivo] = useState(0)

  const ids = props.multiple ? props.value : props.value === null ? [] : [props.value]
  const elegidos = people.filter((p) => ids.includes(p.id))
  const conBuscador = people.length > 8

  const opciones = useMemo(() => {
    const n = normalizar(q.trim())
    const lista: (Persona | null)[] = people.filter((p) => !n || normalizar(p.username).includes(n))
    return allowNone && !n ? [null, ...lista] : lista
  }, [people, q, allowNone])

  function elegir(p: Persona | null) {
    if (props.multiple) {
      if (p === null) props.onChange([])
      else props.onChange(ids.includes(p.id) ? ids.filter((x) => x !== p.id) : [...ids, p.id])
      return
    }
    setAbierto(false)
    boton.current?.focus()
    const nuevo = p?.id ?? null
    if (nuevo !== props.value) props.onChange(nuevo)
  }

  function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      const d = e.key === 'ArrowDown' ? 1 : -1
      setActivo((a) => (a + d + opciones.length) % Math.max(1, opciones.length))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (opciones[activo] !== undefined) elegir(opciones[activo])
    } else if (e.key === 'Tab') setAbierto(false)
  }

  return (
    <>
      <button
        ref={boton}
        type="button"
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={abierto}
        aria-label={label}
        onClick={(e) => {
          e.stopPropagation()
          setQ('')
          setActivo(0)
          setAbierto((v) => !v)
        }}
        className={`flex min-w-0 items-center gap-2 rounded-[7px] px-1 py-1 text-left transition-colors hover:bg-soft disabled:cursor-default disabled:hover:bg-transparent ${className}`}
      >
        {trigger ? (
          trigger(elegidos, abierto)
        ) : elegidos.length > 0 ? (
          <>
            <AvatarStack people={elegidos} size={24} />
            <span className="truncate text-[13px] text-ink">{elegidos.length === 1 ? elegidos[0].username : `${elegidos.length} asignados`}</span>
          </>
        ) : (
          <span className="flex items-center gap-1.5 text-[12.5px] text-label">
            <UserPlus className="size-3.5" /> {placeholder}
          </span>
        )}
      </button>
      <Popover
        open={abierto}
        onClose={() => setAbierto(false)}
        anchor={boton}
        minWidth={220}
        maxHeight={320}
        initialFocus={conBuscador ? 'first' : 'panel'}
        tabIndex={-1}
        className="flex flex-col outline-none"
        onKeyDown={onKey}
      >
        {conBuscador && (
          <div className="relative mb-1 shrink-0">
            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-label" aria-hidden="true" />
            <input
              type="text"
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setActivo(0)
              }}
              placeholder="Buscar persona…"
              aria-label="Buscar persona"
              aria-controls={`${base}-l`}
              aria-activedescendant={`${base}-${activo}`}
              className="w-full rounded-lg bg-soft py-2 pr-2.5 pl-8 text-[13px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px]"
            />
          </div>
        )}
        <div id={`${base}-l`} role="listbox" aria-label={label} aria-multiselectable={props.multiple || undefined} className="min-h-0 flex-1 overflow-y-auto">
          {opciones.length === 0 && <div className="px-3 py-2 text-[12.5px] text-muted">Nadie coincide.</div>}
          {opciones.map((p, i) => {
            const sel = p === null ? ids.length === 0 : ids.includes(p.id)
            return (
              <div
                key={p?.id ?? 'ninguno'}
                id={`${base}-${i}`}
                role="option"
                aria-selected={sel}
                onMouseEnter={() => setActivo(i)}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => elegir(p)}
                className={`flex cursor-pointer items-center gap-[9px] rounded-lg px-[9px] py-[7px] text-[13.5px] ${i === activo ? 'bg-soft text-ink' : 'text-ink'}`}
              >
                {p === null ? (
                  <>
                    <span className="flex size-[22px] items-center justify-center rounded-full border-[1.5px] border-dashed border-[#c4c8ce] text-label dark:border-line-strong">
                      <X className="size-3" />
                    </span>
                    <span className="flex-1 text-muted">{noneLabel}</span>
                  </>
                ) : (
                  <>
                    <Avatar nombre={p.username} foto={p.foto} size={22} />
                    <span className="min-w-0 flex-1 truncate">{p.username}</span>
                    {p.id === meId && <span className="text-[11px] text-muted">tú</span>}
                  </>
                )}
                {sel && (p !== null || !props.multiple) && <Check className="size-3.5 shrink-0 text-accent" aria-hidden="true" />}
              </div>
            )
          })}
        </div>
      </Popover>
    </>
  )
}
