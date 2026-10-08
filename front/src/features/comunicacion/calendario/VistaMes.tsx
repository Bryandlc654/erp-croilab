import { useRef, useState, type DragEvent, type MouseEvent } from 'react'
import type { EventoCal } from '../schemas'
import { cuposCelda, DIAS_SEMANA, rejillaMes, type PorDia } from './logicaCalendario'
import { ChipEvento, ChipTarea, Festivo } from './piezas'

export type AccionesVista = {
  /* Con Google conectado: clic en hueco → nuevo evento, clic derecho → menú. */
  conGoogle: boolean
  onNuevo: (fecha: string, hora?: string) => void
  onMenuHueco: (e: MouseEvent, fecha: string, hora?: string) => void
  onEvento: (ev: EventoCal, el: HTMLElement) => void
  onMenuEvento: (e: MouseEvent, ev: EventoCal) => void
  onTarea: (id: number) => void
  onVerDia: (fecha: string) => void
}

/* Vista Mes (.cl-grid): Lun…Dom con las semanas completas; los días de los
   meses vecinos van atenuados. Los eventos propios se arrastran a otro día. */
export default function VistaMes({
  d,
  hoy,
  porDia,
  festivos,
  acciones,
  onMoverADia,
}: {
  d: string
  hoy: string
  porDia: Map<string, PorDia>
  festivos: Record<string, string>
  acciones: AccionesVista
  onMoverADia: (ev: EventoCal, fecha: string) => void
}) {
  const semanas = rejillaMes(d)
  const arrastrado = useRef<EventoCal | null>(null)
  const [destino, setDestino] = useState<string | null>(null)
  const { conGoogle } = acciones

  function empezar(e: DragEvent, ev: EventoCal) {
    arrastrado.current = ev
    e.dataTransfer.effectAllowed = 'move'
    try {
      e.dataTransfer.setData('text/plain', ev.id)
    } catch {
      /* algunos navegadores no dejan escribir aquí: da igual */
    }
  }
  function terminar() {
    arrastrado.current = null
    setDestino(null)
  }

  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-card max-sm:rounded-xl">
      <div className="grid grid-cols-7 border-b border-line bg-head">
        {DIAS_SEMANA.map((n) => (
          <span key={n} className="px-2.5 py-3 text-center text-[11px] font-[650] tracking-[.5px] text-muted uppercase max-sm:px-0.5 max-sm:py-2 max-sm:text-[9px] max-sm:tracking-[.2px]">
            {n}
          </span>
        ))}
      </div>
      <div className="grid grid-cols-7">
        {semanas.flat().map(({ iso, delMes }, i) => {
          const g = porDia.get(iso)
          const tareas = g?.tareas ?? []
          const eventos = g ? [...g.todoElDia, ...g.conHora] : []
          const cupo = cuposCelda(tareas.length, eventos.length)
          const fest = festivos[iso]
          const esHoy = iso === hoy
          return (
            <div
              key={iso}
              data-iso={iso}
              onClick={conGoogle ? () => acciones.onNuevo(iso) : undefined}
              onContextMenu={conGoogle ? (e) => acciones.onMenuHueco(e, iso) : undefined}
              onDragOver={(e) => {
                if (!arrastrado.current) return
                e.preventDefault()
                e.dataTransfer.dropEffect = 'move'
                if (destino !== iso) setDestino(iso)
              }}
              onDragLeave={(e) => {
                if (!e.currentTarget.contains(e.relatedTarget as Node | null) && destino === iso) setDestino(null)
              }}
              onDrop={(e) => {
                e.preventDefault()
                const ev = arrastrado.current
                terminar()
                if (ev && ev.dia !== iso) onMoverADia(ev, iso)
              }}
              className={`flex min-h-[126px] min-w-0 flex-col gap-[5px] border-b border-line px-2.5 py-[9px] max-[900px]:min-h-[96px] max-sm:min-h-[62px] max-sm:gap-[3px] max-sm:px-1 max-sm:py-[5px] ${
                (i + 1) % 7 ? 'border-r' : ''
              } ${conGoogle ? 'cursor-cell' : ''} ${
                destino === iso
                  ? 'bg-[#eef4ff] outline-2 -outline-offset-[3px] outline-[#4285F4] outline-dashed dark:bg-soft'
                  : fest
                    ? 'bg-[#fbfaf7] dark:bg-[#1a1812]'
                    : !delMes
                      ? 'bg-[#fcfcfd] dark:bg-[#121212]'
                      : ''
              }`}
            >
              <div className="flex min-w-0 items-center gap-1.5">
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation()
                    acciones.onVerDia(iso)
                  }}
                  title="Ver el día"
                  className={`flex size-6 shrink-0 items-center justify-center rounded-[7px] text-[12px] font-semibold max-sm:size-5 max-sm:text-[11px] ${
                    esHoy ? 'bg-accent text-accent-fg' : delMes ? 'text-[#6b7280] hover:bg-soft dark:text-muted' : 'text-[#b4b8bf] hover:bg-soft dark:text-[#555]'
                  }`}
                >
                  {Number(iso.slice(8))}
                </button>
                {fest && <Festivo nombre={fest} className="text-[9.5px] max-sm:hidden" />}
              </div>
              {fest && <span className="hidden size-[5px] rounded-full bg-[#b08900] max-sm:block" title={fest} aria-hidden="true" />}
              {tareas.slice(0, cupo.tareas).map((t) => (
                <ChipTarea key={`t${t.id}`} t={t} onAbrir={acciones.onTarea} />
              ))}
              {eventos.slice(0, cupo.eventos).map((ev) => (
                <ChipEvento
                  key={`${ev.id}-${ev.color}`}
                  ev={ev}
                  onAbrir={acciones.onEvento}
                  onMenu={acciones.onMenuEvento}
                  arrastrable={ev.editable}
                  onDragStart={empezar}
                  onDragEnd={terminar}
                />
              ))}
              {cupo.mas > 0 && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation()
                    acciones.onVerDia(iso)
                  }}
                  className="self-start rounded px-1 text-[10.5px] font-medium text-muted hover:bg-soft hover:text-ink max-sm:text-[9.5px]"
                >
                  +{cupo.mas} más
                </button>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}
