import type { MouseEvent, ReactNode } from 'react'
import { CalendarDays, Flag } from 'lucide-react'
import EmptyState from '../../../shared/ui/EmptyState'
import { LogoMeet } from '../components/Logos'
import type { EventoCal } from '../schemas'
import { colorEstado } from './estilos'
import { DIAS_SEMANA, diaSemana, type PorDia } from './logicaCalendario'
import type { AccionesVista } from './VistaMes'

function Fila({ color, hora, titulo, extra, onClick, onMenu }: { color: string; hora: string; titulo: ReactNode; extra?: string; onClick: (el: HTMLElement) => void; onMenu?: (e: MouseEvent) => void }) {
  return (
    <button
      type="button"
      onClick={(e) => onClick(e.currentTarget)}
      onContextMenu={onMenu}
      className="flex w-full min-w-0 items-center gap-[11px] rounded-[10px] px-2.5 py-2 text-left transition-colors hover:bg-soft max-sm:px-2 max-sm:py-2.5"
    >
      <span className="size-[9px] shrink-0 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />
      <span className="w-[74px] shrink-0 text-[12px] font-semibold text-muted max-sm:w-[60px] max-sm:text-[11.5px]">{hora}</span>
      <span className="flex min-w-0 flex-1 items-center gap-1.5 text-[13.5px] font-semibold text-ink-strong">
        <span className="truncate">{titulo}</span>
      </span>
      {extra && <span className="max-w-[180px] shrink-0 truncate text-[12px] text-muted max-sm:hidden">{extra}</span>}
    </button>
  )
}

/* Agenda (.ag-wrap): 30 días desde la fecha, solo los que tienen algo. */
export default function VistaAgenda({ dias, hoy, porDia, festivos, acciones }: { dias: string[]; hoy: string; porDia: Map<string, PorDia>; festivos: Record<string, string>; acciones: AccionesVista }) {
  const conAlgo = dias.filter((iso) => {
    const g = porDia.get(iso)
    return !!g && (g.tareas.length > 0 || g.todoElDia.length > 0 || g.conHora.length > 0)
  })

  if (!conAlgo.length) {
    return <EmptyState icon={<CalendarDays />} title="Nada en los próximos 30 días" text="Cuando tengas eventos o reuniones agendadas, aparecerán aquí." />
  }

  const dueno = (ev: EventoCal) => (ev.mio ? '' : (ev.owner?.username ?? ''))

  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-card max-sm:rounded-xl">
      {conAlgo.map((iso) => {
        const g = porDia.get(iso)!
        const esHoy = iso === hoy
        return (
          <div key={iso} className="flex gap-4 border-b border-line2 px-[18px] py-3.5 last:border-b-0 max-sm:gap-3 max-sm:px-3.5 max-sm:py-3">
            <button type="button" onClick={() => acciones.onVerDia(iso)} title="Ver el día" className="w-[52px] shrink-0 self-start pt-0.5 text-center max-sm:w-11">
              <span
                className={`inline-flex items-center justify-center text-[22px] leading-none font-[750] ${esHoy ? 'size-[34px] rounded-[11px] bg-[#3c4149] text-white dark:bg-rev dark:text-rev-fg' : 'text-ink-strong'}`}
              >
                {Number(iso.slice(8))}
              </span>
              <span className="mt-0.5 block text-[10.5px] font-[650] tracking-[.4px] text-muted uppercase">{DIAS_SEMANA[diaSemana(iso)]}</span>
            </button>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
              {festivos[iso] && (
                <div className="flex items-center gap-1.5 py-1 text-[12px] font-bold text-[#b08900] dark:text-warn">
                  <Flag className="size-[13px]" /> {festivos[iso]} · festivo
                </div>
              )}
              {g.tareas.map((t) => (
                <Fila key={`t${t.id}`} color={colorEstado(t.estado)} hora="Entrega" titulo={t.titulo} extra={t.cliente} onClick={() => acciones.onTarea(t.id)} />
              ))}
              {[...g.todoElDia, ...g.conHora].map((ev) => (
                <Fila
                  key={`${ev.id}-${ev.color}`}
                  color={ev.color}
                  hora={ev.todo_el_dia || ev.dia !== iso ? 'Todo el día' : ev.hora}
                  titulo={
                    <>
                      {ev.titulo}
                      {ev.meet && (
                        <span className="ml-1.5 inline-flex align-[-2px]">
                          <LogoMeet size={13} />
                        </span>
                      )}
                    </>
                  }
                  extra={dueno(ev)}
                  onClick={(el) => acciones.onEvento(ev, el)}
                  onMenu={(e) => acciones.onMenuEvento(e, ev)}
                />
              ))}
            </div>
          </div>
        )
      })}
    </div>
  )
}
