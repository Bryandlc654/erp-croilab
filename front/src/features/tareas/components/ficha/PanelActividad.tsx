import { useEffect, useMemo, useRef, useState } from 'react'
import { ListChecks, Plus, X } from 'lucide-react'
import { CommentComposer, CommentThread, type Comentario } from '../../../../shared/ui/rich'
import Avatar from '../../../../shared/ui/Avatar'
import type { Persona } from '../../../../shared/schemas'
import { useAccionesComentarios, useComentarios } from '../../api'
import { comentarioAKit, eventosDeTarea } from '../../ficha'
import { urlBackend, urlFichero } from '../../enviar'
import type { PuntoComentario, TareaDetalle } from '../../schemas'

/* Panel de actividad de la ficha (columna derecha de task.php): historial,
   comentarios con respuestas, reacciones, menciones, su lista de control y
   el compositor abajo. */
export default function PanelActividad({ t, meId, equipo, resaltar }: { t: TareaDetalle; meId: number; equipo: Persona[]; resaltar: number | null }) {
  const consulta = useComentarios(t.id)
  const acciones = useAccionesComentarios(t.id)
  const [respondiendo, setRespondiendo] = useState<Comentario | null>(null)
  const [puntos, setPuntos] = useState<PuntoComentario[] | null>(null)
  const items = useMemo(() => consulta.data?.items ?? [], [consulta.data])
  const comentarios = useMemo(() => items.map((c) => comentarioAKit(c, urlBackend)), [items])
  const porId = useMemo(() => new Map(items.map((c) => [c.id, c])), [items])
  const eventos = useMemo(() => eventosDeTarea(t.actividad, t.created_at), [t.actividad, t.created_at])
  const puede = t.permisos.comentar

  /* Como el antiguo: al llegar comentarios nuevos solo se baja si ya se
     estaba al final (o al abrir, salvo que se venga a un comentario concreto). */
  const caja = useRef<HTMLDivElement>(null)
  const alFinal = useRef(resaltar === null)
  const n = consulta.data?.n ?? 0
  useEffect(() => {
    const el = caja.current
    if (el && alFinal.current) el.scrollTop = el.scrollHeight
  }, [n, consulta.isPending])

  return (
    <div className="flex h-full min-h-0 flex-col">
      <h3 className="mb-4 flex shrink-0 items-center gap-2 text-[15px] font-semibold text-ink-strong">
        Actividad <span className="rounded-full bg-[#e7e8ea] px-2 py-px text-[11px] font-[650] text-[#5c616b] dark:bg-line dark:text-muted">{consulta.data?.n ?? 0}</span>
      </h3>
      <div
        ref={caja}
        onScroll={(e) => {
          const el = e.currentTarget
          alFinal.current = el.scrollHeight - el.scrollTop - el.clientHeight < 40
        }}
        className="min-h-0 flex-1 overflow-y-auto pr-1 [scrollbar-width:thin]"
        id="act"
      >
        {consulta.isPending ? (
          <p className="text-[12.5px] text-muted">Cargando…</p>
        ) : consulta.error ? (
          <p className="text-[12.5px] text-[#b91c1c]">{consulta.error.message}</p>
        ) : (
          <CommentThread
            comments={comentarios}
            events={eventos}
            meId={meId}
            people={equipo}
            canInteract={puede}
            highlightId={resaltar}
            resolveFileUrl={urlFichero}
            onReact={(c, emoji) => acciones.reaccionar.mutate({ cid: Number(c.id), emoji })}
            onReply={setRespondiendo}
            onEdit={(c, cuerpo) => acciones.editar.mutateAsync({ cid: Number(c.id), cuerpo })}
            onDelete={(c) => acciones.borrar.mutateAsync(Number(c.id))}
            renderExtra={(c) => {
              const lista = porId.get(Number(c.id))?.checklist ?? []
              if (!lista.length) return null
              return (
                <ul className="mt-2.5 flex flex-col gap-1">
                  {lista.map((p, i) => {
                    const resp = p.resp ? equipo.find((x) => x.id === p.resp) : undefined
                    return (
                      <li key={i} className="flex items-center gap-2 text-[13px]">
                        <input
                          type="checkbox"
                          checked={p.done}
                          disabled={!puede}
                          onChange={(e) => acciones.marcarPunto.mutate({ cid: Number(c.id), idx: i, done: e.target.checked })}
                          aria-label={p.texto}
                          className="size-[14px] shrink-0 accent-[#12a150]"
                        />
                        <span className={`min-w-0 flex-1 ${p.done ? 'text-label line-through' : 'text-ink'}`}>{p.texto}</span>
                        {resp && <Avatar nombre={resp.username} foto={resp.foto} size={18} />}
                      </li>
                    )
                  })}
                </ul>
              )
            }}
            empty={<p className="text-[12.5px] text-muted">Sin comentarios todavía.</p>}
          />
        )}
      </div>
      {puede && (
        <div className="mt-3 shrink-0">
          {puntos !== null && <PuntosNuevos puntos={puntos} setPuntos={setPuntos} equipo={equipo} />}
          <CommentComposer
            people={equipo}
            replyTo={respondiendo}
            onCancelReply={() => setRespondiendo(null)}
            header={
              <div className="mb-1.5 flex justify-end">
                <button
                  type="button"
                  onClick={() => setPuntos((p) => (p === null ? [{ texto: '', done: false, resp: null }] : null))}
                  className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11.5px] font-semibold transition-colors hover:bg-soft hover:text-ink ${puntos !== null ? 'text-ink' : 'text-muted'}`}
                >
                  <ListChecks className="size-3.5" /> {puntos !== null ? 'Quitar lista de control' : 'Lista de control'}
                </button>
              </div>
            }
            onSend={async ({ cuerpo, archivos, replyTo }) => {
              const checklist = (puntos ?? []).filter((p) => p.texto.trim() !== '')
              await acciones.enviar.mutateAsync({ cuerpo, archivos, replyTo: replyTo === null ? null : Number(replyTo), checklist })
              setPuntos(null)
              alFinal.current = true
            }}
          />
        </div>
      )}
    </div>
  )
}

/* Puntos de la lista de control del comentario que se está escribiendo. */
function PuntosNuevos({ puntos, setPuntos, equipo }: { puntos: PuntoComentario[]; setPuntos: (p: PuntoComentario[] | null) => void; equipo: Persona[] }) {
  const cambiar = (i: number, c: Partial<PuntoComentario>) => setPuntos(puntos.map((p, j) => (j === i ? { ...p, ...c } : p)))
  return (
    <div className="mb-2 rounded-[10px] border border-line bg-card p-2">
      {puntos.map((p, i) => (
        <div key={i} className="flex items-center gap-2 py-0.5">
          <span className="size-[14px] shrink-0 rounded-[4px] border border-line-strong" aria-hidden="true" />
          <input
            autoFocus={i === puntos.length - 1}
            value={p.texto}
            onChange={(e) => cambiar(i, { texto: e.target.value })}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                setPuntos([...puntos, { texto: '', done: false, resp: null }])
              }
            }}
            placeholder="Elemento…"
            aria-label="Elemento de la lista"
            className="min-w-0 flex-1 bg-transparent text-[13px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px]"
          />
          <select
            value={p.resp ?? ''}
            onChange={(e) => cambiar(i, { resp: e.target.value ? Number(e.target.value) : null })}
            aria-label="Responsable del punto"
            className="max-w-[110px] rounded-md bg-soft px-1.5 py-1 text-[12px] text-ink focus:outline-none"
          >
            <option value="">Sin asignar</option>
            {equipo.map((x) => (
              <option key={x.id} value={x.id}>
                {x.username}
              </option>
            ))}
          </select>
          <button type="button" onClick={() => setPuntos(puntos.length === 1 ? null : puntos.filter((_, j) => j !== i))} className="rounded p-0.5 text-label hover:text-[#c0392b]" aria-label="Quitar elemento">
            <X className="size-3.5" />
          </button>
        </div>
      ))}
      <button type="button" onClick={() => setPuntos([...puntos, { texto: '', done: false, resp: null }])} className="mt-1 inline-flex items-center gap-1 text-[12px] font-semibold text-muted hover:text-ink">
        <Plus className="size-3.5" /> Añadir elemento
      </button>
    </div>
  )
}
