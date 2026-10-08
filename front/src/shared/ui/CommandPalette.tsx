import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { ArrowRight, FileText, Search } from 'lucide-react'
import { useCapa, useTrampaFoco } from './capas'

export type ResultadoPaleta = {
  id: string
  titulo: string
  subtitulo?: string
  /* Ruta del front a la que lleva (o `onSelect` para otra cosa). */
  href?: string
  icono?: ReactNode
  onSelect?: () => void
}
export type GrupoPaleta = { titulo: string; resultados: ResultadoPaleta[] }
/* Búsqueda: recibe el texto y una señal para cancelar las respuestas que ya no
   valen (el usuario siguió escribiendo). */
export type BuscarPaleta = (q: string, signal: AbortSignal) => Promise<GrupoPaleta[]>

type Props = {
  open: boolean
  onClose: () => void
  buscar: BuscarPaleta
  initialQuery?: string
  /* Última fila «Ver todos los resultados» (página /buscar?q=). */
  onVerTodos?: (q: string) => void
  minimo?: number
  placeholder?: string
}

const ESPERA = 180

/* Paleta / buscador global (Ctrl+K y «/»), como #gsOv del ERP: espera 180 ms
   sin teclear, mínimo dos letras, descarta respuestas tardías y se maneja con
   ↑ ↓ Enter Esc. */
export default function CommandPalette(props: Props) {
  if (!props.open) return null
  return <PaletaAbierta {...props} />
}

type Fila = { tipo: 'resultado'; r: ResultadoPaleta; grupo: number } | { tipo: 'todos' }

function PaletaAbierta({ onClose, buscar, initialQuery = '', onVerTodos, minimo = 2, placeholder = 'Buscar clientes, tareas, facturas…' }: Props) {
  const navigate = useNavigate()
  const caja = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLInputElement>(null)
  const listaId = useId()
  const [q, setQ] = useState(initialQuery)
  const [res, setRes] = useState<{ q: string; grupos: GrupoPaleta[]; error: string | null }>({ q: '', grupos: [], error: null })
  const [activo, setActivo] = useState(0)

  useCapa(true, { onEscape: onClose, bloquearScroll: true })
  useTrampaFoco(caja, true, input)

  const texto = q.trim()
  const corto = texto.length < minimo
  const cargando = !corto && res.q !== texto

  useEffect(() => {
    if (texto.length < minimo) return
    const ctl = new AbortController()
    const t = setTimeout(() => {
      buscar(texto, ctl.signal)
        .then((grupos) => {
          if (!ctl.signal.aborted) setRes({ q: texto, grupos, error: null })
        })
        .catch((e: unknown) => {
          if (ctl.signal.aborted) return
          setRes({ q: texto, grupos: [], error: e instanceof Error ? e.message : 'No se ha podido buscar.' })
        })
    }, ESPERA)
    return () => {
      clearTimeout(t)
      ctl.abort()
    }
  }, [texto, minimo, buscar])

  const grupos = useMemo(() => (corto || cargando ? [] : res.grupos.filter((g) => g.resultados.length > 0)), [corto, cargando, res.grupos])
  const filas = useMemo<Fila[]>(() => {
    const f: Fila[] = grupos.flatMap((g, i) => g.resultados.map((r) => ({ tipo: 'resultado' as const, r, grupo: i })))
    if (onVerTodos && !corto && !cargando && f.length > 0) f.push({ tipo: 'todos' })
    return f
  }, [grupos, onVerTodos, corto, cargando])

  const sel = filas.length ? Math.min(activo, filas.length - 1) : -1
  const idFila = (i: number) => `${listaId}-${i}`

  useEffect(() => {
    if (sel >= 0) document.getElementById(idFila(sel))?.scrollIntoView?.({ block: 'nearest' })
    // idFila depende solo de listaId, que no cambia.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sel])

  function abrir(f: Fila | undefined) {
    if (!f) {
      if (onVerTodos && !corto) {
        onClose()
        onVerTodos(texto)
      }
      return
    }
    onClose()
    if (f.tipo === 'todos') onVerTodos?.(texto)
    else if (f.r.onSelect) f.r.onSelect()
    else if (f.r.href) navigate(f.r.href)
  }

  let mensaje: string | null = null
  if (corto) mensaje = 'Escribe al menos dos letras.'
  else if (cargando) mensaje = 'Buscando…'
  else if (res.error) mensaje = res.error
  else if (grupos.length === 0) mensaje = `Sin resultados para «${texto}».`

  let indice = -1
  return createPortal(
    <div
      className="fixed inset-0 z-[1250] flex items-start justify-center bg-[rgba(16,18,22,.34)] px-3 pt-[11vh] backdrop-blur-[2px] motion-safe:animate-fade-in max-sm:pt-[7vh] dark:bg-black/60"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
    >
      <div
        ref={caja}
        role="dialog"
        aria-modal="true"
        aria-label="Buscador"
        className="w-[min(620px,92vw)] overflow-hidden rounded-2xl border border-line bg-pop shadow-palette motion-safe:animate-gs-in dark:shadow-[0_24px_60px_rgba(0,0,0,.6)]"
      >
        <div className="flex items-center gap-3 border-b border-line px-[18px] py-3.5">
          <Search className="size-[18px] shrink-0 text-muted" aria-hidden="true" />
          <input
            ref={input}
            type="text"
            value={q}
            onChange={(e) => {
              setQ(e.target.value)
              setActivo(0)
            }}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault()
                if (!filas.length) return
                const d = e.key === 'ArrowDown' ? 1 : -1
                setActivo((sel + d + filas.length) % filas.length)
              } else if (e.key === 'Enter') {
                e.preventDefault()
                abrir(filas[sel])
              }
            }}
            placeholder={placeholder}
            role="combobox"
            aria-expanded={filas.length > 0}
            aria-controls={listaId}
            aria-activedescendant={sel >= 0 ? idFila(sel) : undefined}
            aria-autocomplete="list"
            aria-label="Buscar"
            className="min-w-0 flex-1 bg-transparent text-[16px] text-ink-strong placeholder:text-label focus:outline-none"
          />
          <kbd className="shrink-0 rounded-md border border-line bg-card px-1.5 py-0.5 font-sans text-[10.5px] font-bold text-muted">Esc</kbd>
        </div>

        <div id={listaId} role="listbox" aria-label="Resultados" className="max-h-[56vh] overflow-y-auto p-1.5 max-sm:max-h-[64vh]">
          {mensaje && (
            <p className="px-4 py-[26px] text-center text-[13px] text-muted" role="status">
              {mensaje}
            </p>
          )}
          {grupos.map((g, gi) => (
            <div key={gi} role="group" aria-label={g.titulo}>
              <div className="px-3 pt-2.5 pb-[5px] text-[10.5px] font-bold tracking-[.5px] text-muted uppercase" aria-hidden="true">
                {g.titulo}
              </div>
              {g.resultados.map((r) => {
                indice++
                const i = indice
                const on = i === sel
                return (
                  <div
                    key={r.id}
                    id={idFila(i)}
                    role="option"
                    aria-selected={on}
                    onMouseMove={() => sel !== i && setActivo(i)}
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => abrir(filas[i])}
                    className={`flex cursor-pointer items-start gap-3 rounded-[11px] px-3 py-[11px] ${on ? 'bg-accent-soft' : ''}`}
                  >
                    <span className={`flex size-8 shrink-0 items-center justify-center rounded-[9px] text-muted [&>svg]:size-4 ${on ? 'bg-card' : 'bg-soft'}`}>{r.icono ?? <FileText />}</span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13.5px] font-semibold text-ink-strong">{r.titulo}</span>
                      {r.subtitulo && <span className="mt-1 line-clamp-2 block text-[11.5px] leading-[1.45] text-muted">{r.subtitulo}</span>}
                    </span>
                  </div>
                )
              })}
            </div>
          ))}
          {filas.at(-1)?.tipo === 'todos' && (
            <div
              id={idFila(filas.length - 1)}
              role="option"
              aria-selected={sel === filas.length - 1}
              onMouseMove={() => setActivo(filas.length - 1)}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => abrir({ tipo: 'todos' })}
              className={`mt-1 flex cursor-pointer items-center gap-3 rounded-[11px] border-t border-line2 px-3 py-[11px] text-[13px] font-semibold text-ink ${sel === filas.length - 1 ? 'bg-accent-soft' : ''}`}
            >
              <ArrowRight className="size-4 text-muted" aria-hidden="true" /> Ver todos los resultados
            </div>
          )}
        </div>

        <div className="flex gap-3.5 border-t border-line px-4 py-[9px] text-[11.5px] text-muted max-sm:hidden" aria-hidden="true">
          <span>↑↓ moverse</span>
          <span>Enter abrir</span>
          <span>Esc cerrar</span>
        </div>
      </div>
    </div>,
    document.body,
  )
}
