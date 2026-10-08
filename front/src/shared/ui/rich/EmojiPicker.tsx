import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Apple, Car, Clock3, Flag, Hand, Heart, Lightbulb, PawPrint, Search, Smile, Trophy } from 'lucide-react'
import Popover, { type AnclaPopover } from '../Popover'
import type { Placement } from '../posicion'
import { buscarEmojis, cargarEmojis, CATEGORIAS_EMOJI, CLAVE_RECIENTES, guardarReciente, leerRecientes, type Emoji } from '../../lib/emojis'

const ICONO: Record<string, ReactNode> = {
  recientes: <Clock3 />,
  caras: <Smile />,
  personas: <Hand />,
  animales: <PawPrint />,
  comida: <Apple />,
  actividades: <Trophy />,
  viajes: <Car />,
  objetos: <Lightbulb />,
  simbolos: <Heart />,
  banderas: <Flag />,
}
const COLUMNAS = 8
const FUENTE_EMOJI = '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Segoe UI Symbol",sans-serif'

export type EmojiPickerProps = {
  open: boolean
  onClose: () => void
  anchor: AnclaPopover
  /* Se llama con el carácter. Elegir NO cierra (como el antiguo). */
  onPick: (emoji: string) => void
  placement?: Placement
  /* Clave de localStorage de los recientes. */
  recentKey?: string
}

/* Selector de emojis nativos (assets/emoji del antiguo): 340px, categorías +
   Recientes, buscador en español, teclado (flechas, Intro, Esc). */
export default function EmojiPicker(props: EmojiPickerProps) {
  return (
    <Popover
      open={props.open}
      onClose={props.onClose}
      anchor={props.anchor}
      placement={props.placement ?? 'bottom-start'}
      width={340}
      minWidth={0}
      initialFocus="first"
      unstyled
      className="flex flex-col overflow-hidden rounded-[14px] border border-line bg-pop shadow-pop dark:shadow-[0_18px_46px_rgba(0,0,0,.5)]"
      role="dialog"
      aria-label="Emojis"
    >
      <Panel {...props} />
    </Popover>
  )
}

type Seccion = { id: string; label: string; items: Emoji[] }

function Panel({ onPick, recentKey = CLAVE_RECIENTES }: EmojiPickerProps) {
  const [datos, setDatos] = useState<Emoji[] | null>(null)
  const [error, setError] = useState(false)
  const [q, setQ] = useState('')
  const [activo, setActivo] = useState(-1)
  const [sobre, setSobre] = useState<Emoji | null>(null)
  const [cat, setCat] = useState('caras')
  // Los recientes se leen al abrir y no se reordenan mientras está abierto.
  const [recientes] = useState(() => leerRecientes(recentKey))
  const cuerpo = useRef<HTMLDivElement>(null)

  useEffect(() => {
    let vivo = true
    cargarEmojis()
      .then((d) => vivo && setDatos(d))
      .catch(() => vivo && setError(true))
    return () => {
      vivo = false
    }
  }, [])

  const secciones = useMemo<Seccion[]>(() => {
    if (!datos) return []
    const porEmoji = new Map(datos.map((d) => [d.e, d]))
    const out: Seccion[] = []
    if (recientes.length) out.push({ id: 'recientes', label: 'Recientes', items: recientes.map((e) => porEmoji.get(e) ?? { e, n: '', k: [], g: -1 }) })
    for (const c of CATEGORIAS_EMOJI) if (c.grupo !== null) out.push({ id: c.id, label: c.label, items: datos.filter((d) => d.g === c.grupo) })
    return out
  }, [datos, recientes])

  const resultados = useMemo(() => (datos && q.trim() ? buscarEmojis(datos, q) : null), [datos, q])
  const plano = useMemo(() => resultados ?? secciones.flatMap((s) => s.items), [resultados, secciones])
  const actual = activo >= 0 ? plano[activo] : null

  function elegir(em: Emoji) {
    onPick(em.e)
    guardarReciente(em.e, recentKey)
  }

  useEffect(() => {
    if (activo < 0) return
    cuerpo.current?.querySelector(`[data-i="${activo}"]`)?.scrollIntoView({ block: 'nearest' })
  }, [activo])

  function onKey(e: KeyboardEvent<HTMLInputElement>) {
    const n = plano.length
    if (!n) return
    let j: number | null = null
    if (e.key === 'ArrowDown') j = activo < 0 ? 0 : Math.min(n - 1, activo + COLUMNAS)
    else if (e.key === 'ArrowUp') j = activo < 0 ? null : activo - COLUMNAS < 0 ? -1 : activo - COLUMNAS
    else if (e.key === 'ArrowRight' && activo >= 0) j = Math.min(n - 1, activo + 1)
    else if (e.key === 'ArrowLeft' && activo >= 0) j = Math.max(0, activo - 1)
    else if (e.key === 'Enter') {
      e.preventDefault()
      const em = plano[activo >= 0 ? activo : 0]
      if (em) elegir(em)
      return
    }
    if (j === null) return
    e.preventDefault()
    setActivo(j)
  }

  function irA(id: string) {
    setQ('')
    setCat(id)
    const el = cuerpo.current?.querySelector<HTMLElement>(`[data-sec="${id}"]`)
    if (el && cuerpo.current) cuerpo.current.scrollTop = el.offsetTop - 4
  }

  // La pestaña activa sigue al scroll.
  function onScroll() {
    const c = cuerpo.current
    if (!c || resultados) return
    let visible = secciones[0]?.id
    for (const el of c.querySelectorAll<HTMLElement>('[data-sec]')) if (el.offsetTop - 8 <= c.scrollTop && el.dataset.sec) visible = el.dataset.sec
    if (visible && visible !== cat) setCat(visible)
  }

  let i = 0
  const celda = (em: Emoji) => {
    const k = i++
    return (
      <button
        key={k}
        type="button"
        data-i={k}
        tabIndex={-1}
        title={em.n || undefined}
        aria-label={em.n || em.e}
        onMouseEnter={() => setSobre(em)}
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => {
          setActivo(k)
          elegir(em)
        }}
        className={`flex aspect-square items-center justify-center rounded-lg text-[22px] leading-none transition-colors hover:bg-soft ${k === activo ? 'bg-accent-soft ring-1 ring-line-strong' : ''}`}
        style={{ fontFamily: FUENTE_EMOJI }}
      >
        {em.e}
      </button>
    )
  }

  const pie = actual ?? sobre
  return (
    <>
      <div className="shrink-0 px-2.5 pt-2.5">
        <div className="relative">
          <Search className="pointer-events-none absolute top-1/2 left-[11px] size-[15px] -translate-y-1/2 text-label" aria-hidden="true" />
          <input
            type="text"
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setActivo(-1)
            }}
            onKeyDown={onKey}
            placeholder="Buscar emoji…"
            aria-label="Buscar emoji"
            className="w-full rounded-[10px] border-0 bg-soft py-2 pr-[11px] pl-[33px] text-[14px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px]"
          />
        </div>
        <div className="mt-2 flex justify-between gap-0.5" role="tablist" aria-label="Categorías">
          {CATEGORIAS_EMOJI.filter((c) => c.id !== 'recientes' || recientes.length > 0).map((c) => {
            const on = !resultados && cat === c.id
            return (
              <button
                key={c.id}
                type="button"
                role="tab"
                aria-selected={on}
                title={c.label}
                aria-label={c.label}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => irA(c.id)}
                className={`relative flex h-8 flex-1 items-center justify-center rounded-lg text-ink transition-[background-color,opacity] hover:bg-soft hover:opacity-100 [&>svg]:size-[17px] ${on ? 'bg-accent-soft opacity-100 after:absolute after:inset-x-[22%] after:-bottom-1.5 after:h-0.5 after:rounded-full after:bg-ink' : 'opacity-70'}`}
              >
                {ICONO[c.id]}
              </button>
            )
          })}
        </div>
      </div>
      <div className="mx-2.5 mt-[7px] h-px shrink-0 bg-line" />
      <div ref={cuerpo} onScroll={onScroll} className="relative h-[300px] overflow-y-auto overscroll-contain px-2 pt-1 pb-2" onMouseLeave={() => setSobre(null)}>
        {error ? (
          <p className="px-2 py-10 text-center text-[13px] text-muted">No se han podido cargar los emojis.</p>
        ) : !datos ? (
          <p className="px-2 py-10 text-center text-[13px] text-muted">Cargando…</p>
        ) : resultados ? (
          resultados.length ? (
            <div className="grid grid-cols-8 gap-px pt-1">{resultados.map(celda)}</div>
          ) : (
            <p className="px-2 py-10 text-center text-[13px] text-muted">Ningún emoji coincide.</p>
          )
        ) : (
          secciones.map((s) => (
            <section key={s.id} data-sec={s.id} aria-label={s.label} className="[content-visibility:auto] [contain-intrinsic-size:auto_300px]">
              <h3 className="sticky top-0 z-[1] bg-pop px-1 pt-2 pb-1 text-[11px] font-semibold tracking-[.4px] text-muted uppercase">{s.label}</h3>
              <div className="grid grid-cols-8 gap-px">{s.items.map(celda)}</div>
            </section>
          ))
        )}
      </div>
      <div className="flex h-9 shrink-0 items-center gap-2 border-t border-line px-3 text-[12px] text-muted">
        {pie ? (
          <>
            <span className="text-[18px] leading-none" style={{ fontFamily: FUENTE_EMOJI }} aria-hidden="true">
              {pie.e}
            </span>
            <span className="truncate first-letter:uppercase">{pie.n}</span>
          </>
        ) : (
          <span>Elige un emoji · Ctrl+. para abrir</span>
        )}
      </div>
    </>
  )
}

/* Botón de barra (cara dibujada, no un emoji) que abre el selector anclado a sí mismo. */
export function EmojiButton({ onPick, label = 'Emoji', className = '', placement }: { onPick: (emoji: string) => void; label?: string; className?: string; placement?: Placement }) {
  const [abierto, setAbierto] = useState(false)
  const boton = useRef<HTMLButtonElement>(null)
  return (
    <>
      <button
        ref={boton}
        type="button"
        title={label}
        aria-label={label}
        aria-expanded={abierto}
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => setAbierto((v) => !v)}
        className={`inline-flex rounded-[7px] p-1.5 text-label transition-colors hover:bg-soft hover:text-ink [&>svg]:size-4 ${abierto ? 'bg-soft text-ink' : ''} ${className}`}
      >
        <Smile />
      </button>
      <EmojiPicker open={abierto} onClose={() => setAbierto(false)} anchor={boton} onPick={onPick} placement={placement} />
    </>
  )
}
