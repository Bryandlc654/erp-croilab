import { useRef, useState, type ReactNode } from 'react'
import { Check } from 'lucide-react'
import Checkbox from '../../../shared/ui/Checkbox'
import Popover from '../../../shared/ui/Popover'
import StatusPill from '../../../shared/ui/StatusPill'
import { colorFase, nombreFase } from '../logica'
import type { Etiqueta, Fase } from '../schemas'

/* Piezas pequeñas que comparten las pantallas del CRM. */

/** Píldora sólida de la fase («.cm-fase»). */
export function FaseBadge({ fases, fase, size = 'md' }: { fases: Fase[] | undefined; fase: string; size?: 'sm' | 'md' }) {
  return <StatusPill variant="solid" color={colorFase(fases, fase)} label={nombreFase(fases, fase)} size={size} />
}

/** Etiqueta sólida de color (10px/700, radio 5) bajo el nombre o en la tarjeta. */
export function EtiquetaChip({ e, className = '' }: { e: Etiqueta; className?: string }) {
  return (
    <span className={`inline-flex max-w-[140px] items-center truncate rounded-[5px] px-[7px] py-px text-[10px] leading-[1.6] font-bold text-white ${className}`} style={{ backgroundColor: e.color }}>
      {e.nombre}
    </span>
  )
}

const OPCION = 'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[13px] text-[#4c515b] hover:bg-soft hover:text-ink dark:text-nav-ink max-sm:py-2.5'
const CABECERA = 'px-2.5 pt-[7px] pb-[5px] text-[10.5px] font-bold tracking-[.5px] text-muted uppercase'

/** Píldora de fase con su desplegable (.cm-pop): cambio al momento, el servidor lo confirma. */
export function FasePicker({
  fases,
  value,
  onChange,
  disabled = false,
  size = 'md',
}: {
  fases: Fase[] | undefined
  value: string
  onChange: (slug: string) => void
  disabled?: boolean
  size?: 'sm' | 'md'
}) {
  const ancla = useRef<HTMLSpanElement>(null)
  const [abierto, setAbierto] = useState(false)
  if (disabled) return <FaseBadge fases={fases} fase={value} size={size} />
  return (
    <span ref={ancla} className="inline-flex" onClick={(e) => e.stopPropagation()}>
      <StatusPill
        variant="solid"
        color={colorFase(fases, value)}
        label={nombreFase(fases, value)}
        size={size}
        chevron
        open={abierto}
        onClick={() => setAbierto((v) => !v)}
        aria-label={`Embudo de venta: ${nombreFase(fases, value)}. Cambiar`}
      />
      <Popover open={abierto} onClose={() => setAbierto(false)} anchor={ancla} minWidth={198} maxHeight={420} initialFocus="first" closeOnScroll="close" role="listbox" aria-label="Embudo de venta">
        <div className={CABECERA}>Embudo de venta</div>
        {(fases ?? []).map((f) => (
          <button
            key={f.slug}
            type="button"
            role="option"
            aria-selected={f.slug === value}
            onClick={() => {
              setAbierto(false)
              if (f.slug !== value) onChange(f.slug)
            }}
            className={`${OPCION} ${f.slug === value ? 'font-[650] text-ink' : ''}`}
          >
            <span className="size-[9px] shrink-0 rounded-full" style={{ backgroundColor: f.color }} aria-hidden="true" />
            <span className="min-w-0 flex-1 truncate">{f.nombre}</span>
            {f.slug === value && <Check className="size-3.5 shrink-0" />}
          </button>
        ))}
      </Popover>
    </span>
  )
}

/** Servicios del contacto: chips; clic abre casillas (multi) y guarda al cerrar. */
export function ServiciosPicker({
  opciones,
  value,
  onChange,
  disabled = false,
  vacio = '—',
}: {
  opciones: string[]
  value: string[]
  onChange: (v: string[]) => void
  disabled?: boolean
  vacio?: ReactNode
}) {
  const ancla = useRef<HTMLButtonElement>(null)
  const [abierto, setAbierto] = useState(false)
  const [sel, setSel] = useState<string[]>(value)
  const chips = (
    <span className="flex min-w-0 flex-nowrap items-center gap-1">
      {value.length === 0 && <span className="text-label">{vacio}</span>}
      {value.map((s) => (
        <span key={s} className="rounded-md bg-chip px-2 py-[2px] text-[11px] font-semibold text-[#5c616b] dark:text-ink">
          {s}
        </span>
      ))}
    </span>
  )
  if (disabled) return chips
  const todas = Array.from(new Set([...opciones, ...value]))
  return (
    <>
      <button
        ref={ancla}
        type="button"
        onClick={(e) => {
          e.stopPropagation()
          setSel(value)
          setAbierto(true)
        }}
        className="flex min-h-[30px] w-full items-center rounded-[7px] px-2 py-1 text-left hover:bg-soft"
        aria-label="Cambiar servicios"
      >
        {chips}
      </button>
      <Popover
        open={abierto}
        onClose={() => {
          setAbierto(false)
          const a = [...sel].sort().join('|')
          const b = [...value].sort().join('|')
          if (a !== b) onChange(sel)
        }}
        anchor={ancla}
        minWidth={198}
        initialFocus="first"
        closeOnScroll="close"
        aria-label="Servicios"
      >
        <div className={CABECERA}>Servicios</div>
        {todas.map((s) => (
          <label key={s} className={`${OPCION} cursor-pointer`}>
            <Checkbox checked={sel.includes(s)} onChange={(on) => setSel((x) => (on ? [...x, s] : x.filter((y) => y !== s)))} />
            <span className="min-w-0 flex-1 truncate">{s}</span>
          </label>
        ))}
      </Popover>
    </>
  )
}

/** Título de sección de la ficha (.pf-sec): 12px en mayúsculas. */
export function Seccion({ titulo, accion, children, className = '' }: { titulo: ReactNode; accion?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={`mt-6 first:mt-0 ${className}`}>
      <div className="mb-3 flex items-center gap-2">
        <h3 className="min-w-0 flex-1 text-[12px] font-[650] tracking-[.5px] text-muted uppercase">{titulo}</h3>
        {accion}
      </div>
      {children}
    </section>
  )
}

/** Color de las etiquetas de tipo de comentario y estado de propuesta (.pf-tag). */
const TONOS_TIPO: Record<string, { bg: string; fg: string }> = {
  nota: { bg: '#eef0f3', fg: '#5c616b' },
  llamada: { bg: '#e6f6ee', fg: '#12854a' },
  whatsapp: { bg: '#e3f7ed', fg: '#0f7a3d' },
  email: { bg: '#e8effc', fg: '#2f6df6' },
  reunion: { bg: '#fdf1e3', fg: '#c76a12' },
  enviada: { bg: '#eef0f3', fg: '#5c616b' },
  vista: { bg: '#e8effc', fg: '#2f6df6' },
  aceptada: { bg: '#e6f6ee', fg: '#12854a' },
  rechazada: { bg: '#fdecec', fg: '#e5484d' },
}

export function TipoTag({ tipo, label }: { tipo: string; label: string }) {
  const t = TONOS_TIPO[tipo] ?? TONOS_TIPO.nota
  return (
    <span className="rounded-md px-[7px] py-[2px] text-[10px] font-bold dark:!bg-soft dark:!text-ink" style={{ backgroundColor: t.bg, color: t.fg }}>
      {label}
    </span>
  )
}
