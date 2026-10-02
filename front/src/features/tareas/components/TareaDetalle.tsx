import { useEffect } from 'react'
import { CalendarDays, Flag, MessageSquare, Paperclip, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import EstadoCirculo from './EstadoCirculo'
import { ESTADOS, PRIORIDADES } from '../constantes'
import { useTarea } from '../api'

/* La descripción y los comentarios se guardan como HTML del editor del ERP. Aquí
   se enseña solo su texto: nunca se inyecta ese HTML en la página. */
function texto(html: string | null | undefined) {
  if (!html) return ''
  const doc = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n'), 'text/html')
  return (doc.body.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim()
}

function fecha(iso: string | null) {
  if (!iso) return '—'
  const [y, m, d] = iso.slice(0, 10).split('-')
  return `${d}/${m}/${y.slice(2)}`
}

export default function TareaDetalle({ id, onCerrar }: { id: number; onCerrar: () => void }) {
  const { data, error } = useTarea(id)

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
      <div className="relative flex h-full w-full max-w-[520px] flex-col overflow-y-auto border-l border-line bg-page shadow-2xl">
        <div className="flex items-start gap-3 border-b border-line px-6 py-5">
          <div className="min-w-0 flex-1">
            <p className="mb-1 truncate text-[12px] font-medium text-muted">
              {t ? [t.client_name, t.list_name].filter(Boolean).join(' · ') : ' '}
            </p>
            <h2 className="text-[19px] leading-snug font-semibold text-ink-strong">{t?.titulo ?? (error ? 'No disponible' : 'Cargando…')}</h2>
          </div>
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
                {fecha(t.fecha_inicio)} → {fecha(t.due_date)}
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
              <p className="text-[13.5px] leading-relaxed whitespace-pre-wrap text-ink">{texto(t.descripcion) || <span className="text-label">Sin descripción.</span>}</p>
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
                      {texto(c.texto)}
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
                <ul className="flex flex-col gap-1 text-[13px] text-ink">
                  {t.adjuntos.map((a) => (
                    <li key={a.id} className="truncate">
                      {a.nombre || a.filename}
                    </li>
                  ))}
                </ul>
              </section>
            )}

            <section>
              <h3 className="mb-3 flex items-center gap-1.5 text-[10.5px] font-bold tracking-[.7px] text-label uppercase">
                <MessageSquare className="size-3" /> Comentarios · {t.comentarios.length}
              </h3>
              <ul className="flex flex-col gap-4">
                {t.comentarios.map((c) => (
                  <li key={c.id} className="flex gap-2.5">
                    <Avatar nombre={c.username ?? '?'} size={24} />
                    <div className="min-w-0 flex-1">
                      <p className="text-[12px] text-muted">
                        <b className="font-semibold text-ink-strong">{c.username ?? 'Alguien'}</b> · {fecha(c.created_at)}
                      </p>
                      <p className="mt-0.5 text-[13px] leading-relaxed whitespace-pre-wrap text-ink">{texto(c.cuerpo)}</p>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          </div>
        )}
      </div>
    </div>
  )
}
