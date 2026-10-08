import { useEffect, useRef, useState, type ReactNode } from 'react'
import { CornerUpLeft, MoreHorizontal, Pencil, SmilePlus, Trash2 } from 'lucide-react'
import Avatar from '../Avatar'
import Button from '../Button'
import { MenuItem, MenuPanel, MenuSeparator } from '../Menu'
import type { AnclaPopover } from '../Popover'
import { useConfirm } from '../useConfirm'
import EmojiPicker from './EmojiPicker'
import ReactionChips from './ReactionChips'
import RichTextView from './RichTextView'
import RichTextEditor from './RichTextEditor'
import { AttachmentList, Lightbox } from './Adjuntos'
import { BARRA_COMENTARIO, type RichTextEditorHandle } from './richTextTipos'
import type { Comentario, EventoActividad, IdComentario } from './comentariosTipos'
import { extracto } from '../../lib/richtext'
import { horaRelativa } from '../../lib/formato'
import { intercalarPorFecha, repartirImagenes } from '../../lib/comentarios'
import type { PersonaMencion } from '../../lib/menciones'
import './rich.css'

export type CommentThreadProps = {
  comments: Comentario[]
  /* Líneas de sistema, intercaladas por fecha. */
  events?: EventoActividad[]
  /* Mi id: los míos se pueden editar y borrar. */
  meId?: number | string
  /* Personas (avatares en las menciones y @ al editar). */
  people?: PersonaMencion[]
  /* ¿Puedo reaccionar y responder? (solo lectura si no). */
  canInteract?: boolean
  onReact?: (c: Comentario, emoji: string) => void
  /* «Responder»: el compositor muestra la cita (ver CommentComposer.replyTo). */
  onReply?: (c: Comentario) => void
  /* Editar el propio: si no se pasa, no hay «Editar». */
  onEdit?: (c: Comentario, cuerpo: string) => Promise<unknown> | void
  /* Borrar el propio (se confirma aquí). */
  onDelete?: (c: Comentario) => Promise<unknown> | void
  resolveFileUrl?: (fn: string) => string | null | undefined
  /* Comentario enlazado (#c123): se resalta al montar. */
  highlightId?: IdComentario | null
  /* Debajo del texto de cada comentario (p. ej. su lista de control). */
  renderExtra?: (c: Comentario) => ReactNode
  empty?: ReactNode
  className?: string
}

/* Hilo de comentarios del panel de actividad (task.php): burbujas, citas de
   respuesta, reacciones, menciones, adjuntos y acciones al pasar el ratón.
   Solo presentación: las llamadas a la API van en los callbacks. */
export default function CommentThread({
  comments,
  events,
  meId,
  people,
  canInteract = true,
  onReact,
  onReply,
  onEdit,
  onDelete,
  resolveFileUrl,
  highlightId,
  renderExtra,
  empty = <p className="text-[12.5px] text-muted">Sin comentarios todavía.</p>,
  className = '',
}: CommentThreadProps) {
  const nodos = useRef(new Map<IdComentario, HTMLElement>())
  const [destello, setDestello] = useState<{ id: IdComentario; tipo: 'cita' | 'enlace'; n: number } | null>(null)
  const [menu, setMenu] = useState<{ c: Comentario; ancla: AnclaPopover } | null>(null)
  const [reaccionar, setReaccionar] = useState<{ c: Comentario; ancla: AnclaPopover } | null>(null)
  const [editando, setEditando] = useState<IdComentario | null>(null)
  const [lightbox, setLightbox] = useState<{ urls: string[]; i: number } | null>(null)
  const { confirm } = useConfirm()
  const porId = new Map(comments.map((c) => [c.id, c]))
  const lista = intercalarPorFecha(comments, events)

  function irA(id: IdComentario, tipo: 'cita' | 'enlace') {
    const el = nodos.current.get(id)
    if (!el) return
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
    setDestello((d) => ({ id, tipo, n: (d?.n ?? 0) + 1 }))
  }

  // Enlace profundo (#c<ID>): resalte azul 2,5 s.
  useEffect(() => {
    if (highlightId === null || highlightId === undefined) return
    const t = setTimeout(() => irA(highlightId, 'enlace'), 60)
    return () => clearTimeout(t)
  }, [highlightId])

  useEffect(() => {
    if (!destello) return
    const t = setTimeout(() => setDestello(null), destello.tipo === 'enlace' ? 2500 : 1600)
    return () => clearTimeout(t)
  }, [destello])

  async function borrar(c: Comentario) {
    if (!onDelete) return
    if (await confirm({ title: '¿Eliminar comentario?', message: 'Se borrará para todos.', danger: true })) await onDelete(c)
  }

  if (lista.length === 0) return <div className={className}>{empty}</div>

  return (
    <div className={className}>
      {lista.map((x) => {
        if (x.tipo === 'evento') {
          const e = x.e
          return (
            <div key={`e${e.id}`} className="flex items-center gap-2 pt-1 pb-3 text-[12px] text-muted">
              <span className="size-1.5 shrink-0 rounded-full bg-[#c8ccd2] dark:bg-line-strong" aria-hidden="true" />
              <span className="min-w-0">
                {e.actor && <b className="font-semibold text-ink">{e.actor} </b>}
                {e.texto} · {horaRelativa(e.at)}
              </span>
            </div>
          )
        }
        const c = x.c
        const mio = meId !== undefined && c.autor?.id === meId
        const cita = c.replyTo !== null && c.replyTo !== undefined ? porId.get(c.replyTo) : undefined
        const { huecos, debajo } = repartirImagenes(c.cuerpo, c.adjuntos)
        const fl = destello?.id === c.id ? destello : null
        const acciones = canInteract && (onReact || onReply || (mio && (onEdit || onDelete)))
        return (
          <div
            key={c.id}
            id={`c${c.id}`}
            ref={(el) => {
              if (el) nodos.current.set(c.id, el)
              else nodos.current.delete(c.id)
            }}
            className="group/cm relative mb-[18px]"
            onContextMenu={
              acciones
                ? (e) => {
                    e.preventDefault()
                    setMenu({ c, ancla: { x: e.clientX, y: e.clientY } })
                  }
                : undefined
            }
          >
            <div
              key={fl ? `f${fl.n}` : 'n'}
              className={`rounded-xl border border-line bg-card px-[18px] py-[15px] transition-[border-color,background-color,box-shadow] duration-150 group-hover/cm:border-[#d9dade] group-hover/cm:bg-[#fcfcfd] group-hover/cm:shadow-[0_2px_10px_-6px_rgba(16,19,24,.18)] dark:group-hover/cm:border-line-strong dark:group-hover/cm:bg-card max-sm:px-3.5 max-sm:py-3 ${
                fl?.tipo === 'cita' ? 'motion-safe:animate-[cmFlash_1.5s_ease]' : ''
              } ${fl?.tipo === 'enlace' ? '!bg-[var(--rt-flash-enlace)] shadow-[0_0_0_2px_#bcd4ff] dark:shadow-[0_0_0_2px_#2f4f7f]' : ''}`}
            >
              <div className="mb-[13px] flex items-center gap-2 text-[12.5px] max-sm:pr-14">
                <Avatar nombre={c.autor?.username ?? '??'} foto={c.autor?.foto} size={22} />
                <b className="font-semibold text-ink-strong">{c.autor?.username ?? 'Sistema'}</b>
                <span className="text-[11.5px] text-muted" title={c.creado}>
                  {horaRelativa(c.creado)}
                  {c.editado && ' · editado'}
                </span>
              </div>
              {cita && (
                <button
                  type="button"
                  onClick={() => irA(cita.id, 'cita')}
                  title="Ir al comentario"
                  className="mt-0.5 mb-[9px] flex w-full max-w-full flex-col gap-px rounded-r-lg border-l-[3px] border-accent bg-soft px-[11px] py-1.5 text-left transition-colors hover:bg-[#ececed] dark:hover:bg-line"
                >
                  <span className="text-[12px] font-bold text-accent">{cita.autor?.username ?? '—'}</span>
                  <span className="w-full truncate text-[12.5px] text-muted">{extracto(cita.cuerpo, 90, { adjuntos: true }) || 'Comentario'}</span>
                </button>
              )}
              {editando === c.id ? (
                <Edicion c={c} people={people} resolveFileUrl={resolveFileUrl} onCancel={() => setEditando(null)} onSave={async (v) => (await onEdit?.(c, v), setEditando(null))} />
              ) : (
                <RichTextView
                  value={c.cuerpo}
                  people={people}
                  resolveFileUrl={resolveFileUrl}
                  imageSlots={huecos}
                  onImageClick={(u) => setLightbox({ urls: [...huecos], i: Math.max(0, huecos.indexOf(u)) })}
                  className="text-[13.5px] text-[#3a3f47] dark:text-ink"
                />
              )}
              {renderExtra?.(c)}
              {debajo.length > 0 && <AttachmentList items={debajo} className="mt-2" />}
              {(canInteract && onReact) || (canInteract && onReply) || (c.reacciones?.length ?? 0) > 0 ? (
                <div className="mt-[13px] flex items-center gap-2 border-t border-line2 pt-[11px]">
                  <ReactionChips reactions={c.reacciones ?? []} onToggle={canInteract && onReact ? (e) => onReact(c, e) : undefined} thumb={canInteract && !!onReact} />
                  <span className="flex-1" />
                  {canInteract && onReply && (
                    <button
                      type="button"
                      onClick={() => onReply(c)}
                      className="group/r inline-flex items-center gap-1.5 rounded-lg px-[9px] py-1 text-[12.5px] leading-none font-semibold text-[#6b7079] transition-colors hover:bg-soft hover:text-ink dark:text-muted"
                    >
                      <CornerUpLeft className="size-3.5 text-label group-hover/r:text-ink" aria-hidden="true" /> Responder
                    </button>
                  )}
                </div>
              ) : null}
            </div>
            {acciones && editando !== c.id && (
              <div className="pointer-events-none absolute top-0.5 right-0.5 z-[5] flex -translate-y-0.5 gap-0.5 rounded-[9px] border border-line bg-pop p-0.5 opacity-0 shadow-[0_3px_10px_rgba(0,0,0,.08)] transition-[opacity,transform] duration-[120ms] group-focus-within/cm:pointer-events-auto group-focus-within/cm:translate-y-0 group-focus-within/cm:opacity-100 group-hover/cm:pointer-events-auto group-hover/cm:translate-y-0 group-hover/cm:opacity-100 max-sm:pointer-events-auto max-sm:opacity-100">
                {onReact && (
                  <button
                    type="button"
                    title="Reaccionar"
                    aria-label="Reaccionar"
                    onClick={(e) => setReaccionar({ c, ancla: { x: e.currentTarget.getBoundingClientRect().left, y: e.currentTarget.getBoundingClientRect().bottom } })}
                    className="flex size-[26px] items-center justify-center rounded-[7px] text-label hover:bg-soft hover:text-ink"
                  >
                    <SmilePlus className="size-4" />
                  </button>
                )}
                <button
                  type="button"
                  title="Más"
                  aria-label="Más acciones"
                  onClick={(e) => {
                    const r = e.currentTarget.getBoundingClientRect()
                    setMenu({ c, ancla: { x: r.right - 196, y: r.bottom + 4 } })
                  }}
                  className="flex size-[26px] items-center justify-center rounded-[7px] text-label hover:bg-soft hover:text-ink"
                >
                  <MoreHorizontal className="size-4" />
                </button>
              </div>
            )}
          </div>
        )
      })}

      <MenuPanel open={!!menu} onClose={() => setMenu(null)} anchor={menu?.ancla ?? null} minWidth={196} label="Acciones del comentario">
        {menu && (
          <>
            {onReply && (
              <MenuItem icon={<CornerUpLeft />} onSelect={() => onReply(menu.c)}>
                Responder
              </MenuItem>
            )}
            {onReact && (
              <MenuItem icon={<SmilePlus />} onSelect={() => setReaccionar({ c: menu.c, ancla: menu.ancla })}>
                Reaccionar…
              </MenuItem>
            )}
            {meId !== undefined && menu.c.autor?.id === meId && (onEdit || onDelete) && (
              <>
                <MenuSeparator />
                {onEdit && (
                  <MenuItem icon={<Pencil />} onSelect={() => setEditando(menu.c.id)}>
                    Editar
                  </MenuItem>
                )}
                {onDelete && (
                  <MenuItem icon={<Trash2 />} danger onSelect={() => void borrar(menu.c)}>
                    Eliminar
                  </MenuItem>
                )}
              </>
            )}
          </>
        )}
      </MenuPanel>
      <EmojiPicker
        open={!!reaccionar}
        onClose={() => setReaccionar(null)}
        anchor={reaccionar?.ancla ?? null}
        onPick={(e) => {
          if (reaccionar) onReact?.(reaccionar.c, e)
          setReaccionar(null)
        }}
      />
      <Lightbox items={(lightbox?.urls ?? []).map((u, i) => ({ id: i, nombre: 'Imagen', url: u, mime: 'image/*' }))} index={lightbox?.i ?? null} onClose={() => setLightbox(null)} onIndexChange={(i) => setLightbox((l) => (l ? { ...l, i } : l))} />
    </div>
  )
}

function Edicion({
  c,
  people,
  resolveFileUrl,
  onCancel,
  onSave,
}: {
  c: Comentario
  people?: PersonaMencion[]
  resolveFileUrl?: (fn: string) => string | null | undefined
  onCancel: () => void
  onSave: (v: string) => Promise<unknown>
}) {
  const ed = useRef<RichTextEditorHandle>(null)
  const [guardando, setGuardando] = useState(false)
  async function guardar() {
    const v = ed.current?.getValue() ?? c.cuerpo
    setGuardando(true)
    try {
      await onSave(v)
    } finally {
      setGuardando(false)
    }
  }
  return (
    <div className="rounded-[10px] border border-line bg-card px-2.5 py-2 focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--c-accent-soft)]">
      <RichTextEditor
        ref={ed}
        value={c.cuerpo}
        variant="compact"
        autoFocus
        mentions={people}
        resolveFileUrl={resolveFileUrl}
        onSubmit={() => void guardar()}
        toolbar={BARRA_COMENTARIO.filter((h) => h !== 'attach')}
        ariaLabel="Editar comentario"
        toolbarEnd={
          <>
            <Button variant="ghost" size="sm" onClick={onCancel}>
              Cancelar
            </Button>
            <Button size="sm" onClick={() => void guardar()} loading={guardando}>
              Guardar
            </Button>
          </>
        }
      />
    </div>
  )
}
