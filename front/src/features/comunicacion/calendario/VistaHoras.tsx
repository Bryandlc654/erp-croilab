import { useEffect, useLayoutEffect, useRef, useState, type PointerEvent as PE } from 'react'
import { createPortal } from 'react-dom'
import type { EventoCal } from '../schemas'
import { TINTE_BLOQUE, varColor } from './estilos'
import { ALTO_HORA, aHora, arrastrar, caja, colocarEventos, DIAS_SEMANA, pxAMinutos, tramo, type Modo, type PorDia } from './logicaCalendario'
import { ChipEvento, ChipTarea, Festivo } from './piezas'
import type { AccionesVista } from './VistaMes'

type Arrastre = {
  ev: EventoCal
  modo: Modo
  orig: { ini: number; fin: number }
  agarre: number
  x0: number
  y0: number
  movido: boolean
  dia: string
  ini: number
  fin: number
}

const HORAS = Array.from({ length: 24 }, (_, h) => h)
const GUTTER = 56

function minutoEn(col: HTMLElement, clientY: number) {
  return pxAMinutos(clientY - col.getBoundingClientRect().top)
}

function columnaEn(x: number, y: number) {
  const el = document.elementFromPoint(x, y)
  return el instanceof Element ? (el.closest('[data-col]') as HTMLElement | null) : null
}

/* Semana / Día (.tg): cabecera de días, franja «Todo el día» y rejilla de
   24 h × 46 px con los eventos en columnas si se solapan. Los propios se
   mueven y se estiran con el ratón (paso de 15 min). */
export default function VistaHoras({
  dias,
  hoy,
  porDia,
  festivos,
  acciones,
  onMover,
}: {
  dias: string[]
  hoy: string
  porDia: Map<string, PorDia>
  festivos: Record<string, string>
  acciones: AccionesVista
  onMover: (ev: EventoCal, dia: string, ini: number, fin: number) => void
}) {
  const scroll = useRef<HTMLDivElement>(null)
  const [arr, setArr] = useState<Arrastre | null>(null)
  const [tip, setTip] = useState<{ x: number; y: number } | null>(null)
  const recienArrastrado = useRef(false)
  const ahora = useAhora()
  const minAhora = ahora.getHours() * 60 + ahora.getMinutes()
  const semana = dias.length > 1
  const cols = `${GUTTER}px repeat(${dias.length}, minmax(0, 1fr))`
  const { conGoogle } = acciones

  // Al entrar (o cambiar de semana/día) la rejilla se coloca a las 07:00.
  const clave = dias[0]
  useLayoutEffect(() => {
    if (scroll.current) scroll.current.scrollTop = 7 * ALTO_HORA - 12
  }, [clave])

  // Mientras se arrastra, los movimientos se siguen en la ventana entera.
  const vivo = useRef<Arrastre | null>(null)
  useEffect(() => {
    vivo.current = arr
  })
  useEffect(() => {
    if (!arr) return
    const mover = (e: PointerEvent) => {
      const a = vivo.current
      if (!a) return
      if (!a.movido && Math.abs(e.clientX - a.x0) + Math.abs(e.clientY - a.y0) < 4) return
      let dia = a.dia
      let col = document.querySelector<HTMLElement>(`[data-col="${a.dia}"]`)
      if (a.modo === 'mover') {
        const c = columnaEn(e.clientX, e.clientY)
        if (c?.dataset.col) {
          col = c
          dia = c.dataset.col
        }
      }
      if (!col) return
      const t = arrastrar(a.modo, a.orig, minutoEn(col, e.clientY), a.agarre)
      setArr({ ...a, movido: true, dia, ...t })
      setTip({ x: e.clientX, y: e.clientY })
    }
    const soltar = () => {
      const a = vivo.current
      setArr(null)
      setTip(null)
      if (!a?.movido) return
      recienArrastrado.current = true
      setTimeout(() => (recienArrastrado.current = false), 0)
      if (a.dia !== a.ev.dia || a.ini !== a.orig.ini || a.fin !== a.orig.fin) onMover(a.ev, a.dia, a.ini, a.fin)
    }
    window.addEventListener('pointermove', mover)
    window.addEventListener('pointerup', soltar, { once: true })
    window.addEventListener('pointercancel', soltar, { once: true })
    return () => {
      window.removeEventListener('pointermove', mover)
      window.removeEventListener('pointerup', soltar)
      window.removeEventListener('pointercancel', soltar)
    }
    // Solo al empezar/acabar un arrastre; los datos vivos van por la ref.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [arr === null])

  function empezar(e: PE, ev: EventoCal, modo: Modo) {
    // Solo con ratón: en el móvil el dedo desplaza la rejilla.
    if (e.pointerType !== 'mouse' || e.button !== 0) return
    const col = (e.currentTarget as HTMLElement).closest<HTMLElement>('[data-col]')
    if (!col) return
    e.preventDefault()
    e.stopPropagation()
    const orig = tramo(ev)
    setArr({ ev, modo, orig, agarre: minutoEn(col, e.clientY) - orig.ini, x0: e.clientX, y0: e.clientY, movido: false, dia: ev.dia, ...orig })
  }

  const etiquetaTip = arr?.movido ? `${aHora(arr.ini)} – ${arr.fin >= 1440 ? '24:00' : aHora(arr.fin)}` : ''

  return (
    <div className={`overflow-hidden rounded-2xl border border-line bg-card max-sm:rounded-xl ${arr?.movido ? 'cursor-grabbing select-none' : ''}`}>
      <div className={semana ? 'overflow-x-auto max-sm:[-webkit-overflow-scrolling:touch]' : ''}>
        <div className={semana ? 'max-sm:min-w-[660px]' : ''}>
          {/* Cabecera de días */}
          <div className="grid border-b border-line bg-head" style={{ gridTemplateColumns: cols }}>
            <div />
            {dias.map((iso) => {
              const fest = festivos[iso]
              const esHoy = iso === hoy
              return (
                <button
                  key={iso}
                  type="button"
                  onClick={() => acciones.onVerDia(iso)}
                  disabled={!semana}
                  title={fest ? `Festivo: ${fest}` : 'Ver el día'}
                  className="border-r border-line2 px-1.5 py-[9px] text-center last:border-r-0 enabled:hover:bg-soft max-sm:px-0.5 max-sm:py-[7px]"
                >
                  <div className={`text-[10.5px] font-[650] tracking-[.4px] uppercase ${fest ? 'text-[#b08900] dark:text-warn' : 'text-muted'}`}>{DIAS_SEMANA[(new Date(iso + 'T12:00').getDay() + 6) % 7]}</div>
                  <div
                    className={`mx-auto mt-0.5 flex size-[30px] items-center justify-center rounded-full text-[17px] font-bold max-sm:size-[26px] max-sm:text-[15px] ${
                      esHoy ? 'bg-accent text-accent-fg' : 'text-ink-strong'
                    }`}
                  >
                    {Number(iso.slice(8))}
                  </div>
                </button>
              )
            })}
          </div>

          {/* Todo el día: festivo, tareas y eventos de día completo */}
          <div className="grid min-h-[34px] border-b border-line" style={{ gridTemplateColumns: cols }}>
            <div className="flex items-center pl-2 text-[10px] tracking-[.4px] text-muted uppercase">Todo el día</div>
            {dias.map((iso) => {
              const g = porDia.get(iso)
              return (
                <div
                  key={iso}
                  onClick={conGoogle ? () => acciones.onNuevo(iso) : undefined}
                  onContextMenu={conGoogle ? (e) => acciones.onMenuHueco(e, iso) : undefined}
                  className={`flex min-w-0 flex-col gap-[3px] border-r border-line2 px-1.5 py-1 last:border-r-0 ${conGoogle ? 'cursor-cell' : ''}`}
                >
                  {festivos[iso] && <Festivo nombre={festivos[iso]} className="text-[10px]" />}
                  {g?.tareas.map((t) => <ChipTarea key={t.id} t={t} onAbrir={acciones.onTarea} avatares={!semana} className="!rounded-[5px] !py-0.5 !text-[11px]" />)}
                  {g?.todoElDia.map((ev) => (
                    <ChipEvento key={`${ev.id}-${ev.color}`} ev={ev} hora={false} onAbrir={acciones.onEvento} onMenu={acciones.onMenuEvento} className="!rounded-[5px] !py-0.5 !text-[11px]" />
                  ))}
                </div>
              )
            })}
          </div>

          {/* Rejilla de horas */}
          <div ref={scroll} className="max-h-[max(360px,calc(100dvh-330px))] overflow-y-auto max-sm:max-h-[70vh]">
            <div className="relative grid" style={{ gridTemplateColumns: cols, height: 24 * ALTO_HORA }}>
              <div aria-hidden="true">
                {HORAS.map((h) => (
                  <div key={h} className="-translate-y-1.5 pr-2 text-right text-[10.5px] text-muted" style={{ height: ALTO_HORA }}>
                    {h > 0 ? `${String(h).padStart(2, '0')}:00` : ''}
                  </div>
                ))}
              </div>
              {dias.map((iso) => {
                const g = porDia.get(iso)
                const colocados = colocarEventos(g?.conHora ?? [])
                const esHoy = iso === hoy
                // El evento arrastrado se pinta en la columna en la que está ahora.
                const visibles = colocados.filter((c) => !(arr?.movido && c.ev.id === arr.ev.id && c.ev.color === arr.ev.color))
                return (
                  <div
                    key={iso}
                    data-col={iso}
                    onClick={
                      conGoogle
                        ? (e) => {
                            const h = Math.floor(minutoEn(e.currentTarget, e.clientY) / 60)
                            acciones.onNuevo(iso, aHora(h * 60))
                          }
                        : undefined
                    }
                    onContextMenu={
                      conGoogle
                        ? (e) => {
                            const h = Math.floor(minutoEn(e.currentTarget, e.clientY) / 60)
                            acciones.onMenuHueco(e, iso, aHora(h * 60))
                          }
                        : undefined
                    }
                    className={`group/col relative border-r border-line2 last:border-r-0 ${conGoogle ? 'cursor-pointer' : ''} ${esHoy ? 'bg-[#fafcff] dark:bg-white/[.025]' : ''}`}
                    style={{ backgroundImage: `repeating-linear-gradient(to bottom, var(--c-line2) 0 1px, transparent 1px ${ALTO_HORA}px)` }}
                  >
                    {conGoogle && <HuecoHover />}
                    {visibles.map((c) => (
                      <BloqueEvento
                        key={`${c.ev.id}-${c.ev.color}`}
                        ev={c.ev}
                        pos={caja(c)}
                        onPointerDown={empezar}
                        onClick={(el) => !recienArrastrado.current && acciones.onEvento(c.ev, el)}
                        onMenu={acciones.onMenuEvento}
                      />
                    ))}
                    {arr?.movido && arr.dia === iso && (
                      <BloqueEvento ev={arr.ev} pos={caja({ ini: arr.ini, fin: arr.fin, col: 0, cols: 1 })} arrastrando horaTexto={etiquetaTip} />
                    )}
                    {esHoy && (
                      <div className="pointer-events-none absolute right-0 left-0 z-[3] h-0.5 bg-[#ef4444]" style={{ top: (minAhora / 60) * ALTO_HORA }} aria-hidden="true">
                        <span className="absolute -top-[3px] -left-1 size-2 rounded-full bg-[#ef4444]" />
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      </div>
      {tip &&
        etiquetaTip &&
        createPortal(
          <div className="pointer-events-none fixed z-[1400] rounded-md bg-rev px-2 py-[3px] text-[11.5px] font-semibold whitespace-nowrap text-rev-fg" style={{ left: tip.x + 14, top: tip.y + 10 }}>
            {etiquetaTip}
          </div>,
          document.body,
        )}
    </div>
  )
}

/* Resalta la franja de una hora bajo el ratón (.tg-slot:hover del antiguo). */
function HuecoHover() {
  const [y, setY] = useState<number | null>(null)
  return (
    <div
      className="absolute inset-0"
      onMouseMove={(e) => {
        const h = Math.floor((e.clientY - e.currentTarget.getBoundingClientRect().top) / ALTO_HORA)
        setY(h * ALTO_HORA)
      }}
      onMouseLeave={() => setY(null)}
    >
      {y !== null && <div className="pointer-events-none absolute right-0 left-0 bg-[rgba(66,133,244,.06)] dark:bg-white/[.04]" style={{ top: y, height: ALTO_HORA }} />}
    </div>
  )
}

function BloqueEvento({
  ev,
  pos,
  onPointerDown,
  onClick,
  onMenu,
  arrastrando = false,
  horaTexto,
}: {
  ev: EventoCal
  pos: { top: number; height: number; left: number; width: number }
  onPointerDown?: (e: PE, ev: EventoCal, modo: Modo) => void
  onClick?: (el: HTMLElement) => void
  onMenu?: AccionesVista['onMenuEvento']
  arrastrando?: boolean
  horaTexto?: string
}) {
  // Se puede arrastrar si es mío y empieza y acaba el mismo día.
  const movible = ev.editable && !!onPointerDown && (!ev.dia_fin || ev.dia_fin === ev.dia)
  const corto = pos.height < 34
  return (
    <div
      role="button"
      tabIndex={0}
      onPointerDown={movible ? (e) => onPointerDown?.(e, ev, 'mover') : undefined}
      onClick={(e) => {
        e.stopPropagation()
        onClick?.(e.currentTarget)
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onClick?.(e.currentTarget)
        }
      }}
      onContextMenu={(e) => onMenu?.(e, ev)}
      title={`${ev.titulo}${ev.owner ? ` · ${ev.owner.username}` : ''}`}
      style={{
        ...varColor(ev.color),
        top: pos.top,
        height: pos.height,
        left: `calc(${pos.left}% + 2px)`,
        width: `calc(${pos.width}% - 4px)`,
      }}
      className={`absolute z-[2] overflow-hidden rounded-md px-1.5 py-[3px] text-[11px] leading-[1.3] outline-none focus-visible:ring-2 focus-visible:ring-[#4285F4] ${TINTE_BLOQUE} ${
        arrastrando ? 'z-[6] cursor-grabbing opacity-90 shadow-[0_10px_22px_-6px_rgba(0,0,0,.3)]' : movible ? 'cursor-grab hover:brightness-[.97]' : 'cursor-pointer hover:brightness-[.97]'
      }`}
    >
      {movible && <span onPointerDown={(e) => onPointerDown?.(e, ev, 'arriba')} className="absolute -top-0.5 right-0 left-0 z-[3] h-[7px] cursor-ns-resize" aria-hidden="true" />}
      <div className={`truncate font-semibold ${corto ? 'inline' : ''}`}>{ev.titulo}</div>
      {!corto && <div className="truncate text-[10px] opacity-80">{horaTexto || (ev.hora_fin ? `${ev.hora}–${ev.hora_fin}` : ev.hora)}</div>}
      {corto && <span className="ml-1 text-[10px] opacity-80">{horaTexto || ev.hora}</span>}
      {movible && <span onPointerDown={(e) => onPointerDown?.(e, ev, 'abajo')} className="absolute right-0 -bottom-0.5 left-0 z-[3] h-[7px] cursor-ns-resize" aria-hidden="true" />}
    </div>
  )
}

/* Hora actual, refrescada cada minuto (línea roja de «ahora»). */
function useAhora() {
  const [ahora, setAhora] = useState(() => new Date())
  useEffect(() => {
    const t = setInterval(() => setAhora(new Date()), 60_000)
    return () => clearInterval(t)
  }, [])
  return ahora
}

