import { useEffect, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { CalendarDays, ExternalLink, Flag, Paperclip, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import { AttachmentList, RichTextView } from '../../../shared/ui/rich'
import { fechaCorta } from '../../../shared/lib/formato'
import EstadoCirculo from './EstadoCirculo'
import { ESTADOS, PRIORIDADES } from '../constantes'
import { useTarea } from '../api'
import { useEquipo } from '../../nav/api'
import { urlBackend, urlFichero } from '../enviar'

/* Vista rápida de una tarea desde el tablero (cajón a la derecha). La
   descripción llega en el formato del ERP y se pinta con RichTextView: nunca
   se inyecta HTML. Para editar, «Abrir ficha» lleva a /tareas/:id. */
export default function TareaDetalle({ id, onCerrar }: { id: number; onCerrar: () => void }) {
  const { data, error } = useTarea(id)
  const { data: equipoData } = useEquipo()
  const equipo = useMemo(() => equipoData ?? [], [equipoData])

  useEffect(() => {
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && onCerrar()
    document.addEventListener('keydown', esc)
    return () => document.removeEventListener('keydown', esc)
  }, [onCerrar])

  const t = data?.tarea
  const prio = PRIORIDADES.find((p) => p.value === (t?.prioridad ?? 0)) ?? PRIORIDADES[0]

  return (
    <div className="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-label="Detalle de la tarea">
      <button type="button" className="absolute inset-0 bg-black/20 dark:bg-black/50" onClick={onCerrar} aria-label="Cerrar" />
      <div className="relative flex h-full w-full max-w-[520px] flex-col overflow-y-auto border-l border-line bg-page shadow-2xl motion-safe:animate-drawer-in">
        <div className="flex items-start gap-3 border-b border-line px-6 py-5">
          <div className="min-w-0 flex-1">
            <p className="mb-1 truncate text-[12px] font-medium text-muted">{t ? [t.client_name, t.list_name].filter(Boolean).join(' · ') : ' '}</p>
            <h2 className="text-[19px] leading-snug font-semibold text-ink-strong">{t?.titulo ?? (error ? 'No disponible' : 'Cargando…')}</h2>
          </div>
          {t && (
            <Link to={`/tareas/${t.id}`} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-[12.5px] font-semibold text-ink hover:bg-soft">
              <ExternalLink className="size-3.5" /> Abrir ficha
            </Link>
          )}
          <button type="button" onClick={onCerrar} className="rounded-lg p-1.5 text-label hover:bg-soft hover:text-ink" aria-label="Cerrar detalle">
            <X className="size-5" />
          </button>
        </div>

        {error && !t && <p className="px-6 py-5 text-[13px] text-[#b91c1c]">{error.message}</p>}

        {t && (
          <div className="flex flex-col gap-6 px-6 py-5">
            <dl className="grid grid-cols-[130px_1fr] gap-x-4 gap-y-3 text-[13px]">
              <dt className="text-muted">Estado</dt>
              <dd className="flex items-center gap-2 font-medium text-ink">
                <EstadoCirculo estado={t.estado} size={14} /> {ESTADOS[t.estado].label}
              </dd>
              <dt className="text-muted">Asignados</dt>
              <dd className="flex flex-wrap gap-2">
                {t.asignados.length === 0 && <span className="text-label">Sin asignar</span>}
                {t.asignados.map((a) => (
                  <span key={a.id} className="flex items-center gap-1.5 font-medium text-ink">
                    <Avatar nombre={a.username} foto={a.foto} size={20} /> {a.username}
                  </span>
                ))}
              </dd>
              <dt className="flex items-center gap-1.5 text-muted">
                <CalendarDays className="size-3.5" /> Fechas
              </dt>
              <dd className="font-medium text-ink">
                {fechaCorta(t.fecha_inicio, '—')} → {fechaCorta(t.due_date, '—')}
              </dd>
              <dt className="flex items-center gap-1.5 text-muted">
                <Flag className="size-3.5" /> Prioridad
              </dt>
              <dd className="flex items-center gap-1.5 font-semibold text-ink">
                <span className="size-[9px] rounded-[2px]" style={{ backgroundColor: prio.color }} /> {prio.label}
              </dd>
            </dl>

            <section>
              <h3 className="mb-2 text-[10.5px] font-bold tracking-[.7px] text-label uppercase">Descripción</h3>
              {t.descripcion.trim() ? (
                <RichTextView value={t.descripcion} people={equipo} resolveFileUrl={urlFichero} className="text-[13.5px]" />
              ) : (
                <p className="text-[13.5px] text-label">Sin descripción.</p>
              )}
            </section>

            {t.checklist.length > 0 && (
              <section>
                <h3 className="mb-2 text-[10.5px] font-bold tracking-[.7px] text-label uppercase">
                  Lista de control · {t.checklist.filter((c) => c.done).length}/{t.checklist.length}
                </h3>
                <ul className="flex flex-col gap-1.5 text-[13px]">
                  {t.checklist.map((c) => (
                    <li key={c.id} className={`flex items-center gap-2 ${c.done ? 'text-muted line-through' : 'text-ink'}`}>
                      <EstadoCirculo estado={c.done ? 'completada' : 'pendiente'} size={13} />
                      {c.texto}
                    </li>
                  ))}
                </ul>
              </section>
            )}

            {t.adjuntos.length > 0 && (
              <section>
                <h3 className="mb-2 flex items-center gap-1.5 text-[10.5px] font-bold tracking-[.7px] text-label uppercase">
                  <Paperclip className="size-3" /> Adjuntos
                </h3>
                <AttachmentList items={t.adjuntos.map((a) => ({ id: a.id, nombre: a.nombre, url: urlBackend(a.url), mime: a.mime || null }))} />
              </section>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
