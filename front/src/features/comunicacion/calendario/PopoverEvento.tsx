import type { ReactNode } from 'react'
import { CalendarDays, Clock, ExternalLink, FileText, MapPin, Pencil, Repeat, Trash2, UserRound, Users } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Popover, { type AnclaPopover } from '../../../shared/ui/Popover'
import { LogoMeet } from '../components/Logos'
import type { EventoCal } from '../schemas'
import { varColor } from './estilos'
import { fechaLarga, textoHora } from './logicaCalendario'

function Meta({ icon, children }: { icon: ReactNode; children: ReactNode }) {
  return (
    <div className="flex items-start gap-[7px] text-[12.5px] leading-[1.45] text-muted [&>svg]:mt-[2px] [&>svg]:size-[13px] [&>svg]:shrink-0">
      {icon}
      <div className="min-w-0 flex-1 break-words">{children}</div>
    </div>
  )
}

/* Detalle de un evento al hacer clic (.ev-pop del antiguo, 290px). */
export default function PopoverEvento({
  ev,
  anchor,
  onClose,
  onEditar,
  onEliminar,
}: {
  ev: EventoCal | null
  anchor: AnclaPopover
  onClose: () => void
  onEditar: (ev: EventoCal) => void
  onEliminar: (ev: EventoCal) => void
}) {
  if (!ev) return null
  const fecha = ev.dia_fin && ev.dia_fin > ev.dia ? `${fechaLarga(ev.dia)} – ${fechaLarga(ev.dia_fin)}` : fechaLarga(ev.dia)
  return (
    <Popover
      open
      onClose={onClose}
      anchor={anchor}
      offset={6}
      width={290}
      unstyled
      initialFocus="panel"
      tabIndex={-1}
      role="dialog"
      aria-label={ev.titulo}
      className="max-w-[calc(100vw-24px)] rounded-[14px] border border-line bg-pop px-4 py-[15px] shadow-[0_20px_50px_-16px_rgba(16,19,24,.34)] outline-none dark:shadow-[0_20px_50px_rgba(0,0,0,.5)]"
    >
      <div className="mb-2 flex items-start gap-2">
        <span className="mt-[5px] size-[9px] shrink-0 rounded-[3px] bg-(--ev)" style={varColor(ev.color)} aria-hidden="true" />
        <h4 className="min-w-0 flex-1 text-[15px] font-semibold break-words text-ink-strong">{ev.titulo}</h4>
      </div>
      <div className="flex flex-col gap-[3px]">
        <Meta icon={<CalendarDays />}>
          <span className="block first-letter:uppercase">{fecha}</span>
        </Meta>
        <Meta icon={<Clock />}>{textoHora(ev)}</Meta>
        {ev.recurrente && <Meta icon={<Repeat />}>Se repite</Meta>}
        {!ev.mio && ev.owner && <Meta icon={<UserRound />}>Agenda de {ev.owner.username}</Meta>}
        {ev.invitados.length > 0 && <Meta icon={<Users />}>{ev.invitados.join(', ')}</Meta>}
        {ev.ubicacion && <Meta icon={<MapPin />}>{ev.ubicacion}</Meta>}
        {ev.descripcion && (
          <p className="mt-1.5 line-clamp-4 text-[12.5px] leading-[1.5] whitespace-pre-line text-ink" title={ev.descripcion}>
            {ev.descripcion}
          </p>
        )}
      </div>

      {ev.meet_url && (
        <a
          href={ev.meet_url}
          target="_blank"
          rel="noopener noreferrer"
          className="mt-3 flex items-center justify-center gap-2 rounded-[10px] bg-[#1a73e8] px-3 py-2 text-[13px] font-semibold text-white transition-[filter] hover:brightness-110"
        >
          <span className="flex rounded-[5px] bg-white p-0.5">
            <LogoMeet size={14} />
          </span>
          Unirse con Google Meet
        </a>
      )}

      {ev.docs.length > 0 && (
        <div className="mt-2.5 flex flex-col gap-1">
          {ev.docs.map((d) => (
            <a key={d.url} href={d.url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-[9px] bg-soft px-2.5 py-1.5 text-[12.5px] font-medium text-ink hover:bg-chip">
              <FileText className="size-3.5 shrink-0 text-label" />
              <span className="truncate">{d.titulo}</span>
            </a>
          ))}
        </div>
      )}

      <div className="mt-3.5 flex gap-2">
        {ev.editable && (
          <Button variant="ghost" size="sm" icon={<Pencil />} className="flex-1" onClick={() => onEditar(ev)}>
            Editar
          </Button>
        )}
        {ev.link && (
          <Button variant="ghost" size="sm" icon={<ExternalLink />} className="flex-1" href={ev.link} target="_blank" rel="noopener noreferrer">
            {ev.editable ? 'Google' : 'Ver en Google'}
          </Button>
        )}
      </div>
      {ev.editable && (
        <div className="mt-1 text-center">
          <button
            type="button"
            onClick={() => onEliminar(ev)}
            className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-[12.5px] font-semibold text-[#c0343a] hover:underline dark:text-danger"
          >
            <Trash2 className="size-3.5" />
            Eliminar evento
          </button>
        </div>
      )}
    </Popover>
  )
}
