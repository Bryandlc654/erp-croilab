import { memo, useRef, useState, type MouseEvent } from 'react'
import { Check, CheckCheck, MoreHorizontal, SmilePlus } from 'lucide-react'
import { url } from '../../../shared/api/client'
import { colorDe } from '../../../shared/lib/avatar'
import Avatar from '../../../shared/ui/Avatar'
import Popover from '../../../shared/ui/Popover'
import { AttachmentChip, QuickReactions, ReactionChips, type Adjunto } from '../../../shared/ui/rich'
import { horaMensaje, trozosTexto } from '../logica'
import type { Mensaje } from '../schemas'

/* Texto de un mensaje sin HTML: enlaces http(s) y @menciones del equipo. */
export function TextoChat({ texto, nombres, yo, propio }: { texto: string; nombres: string[]; yo: string; propio: boolean }) {
  return (
    <>
      {trozosTexto(texto, nombres, yo).map((t, i) =>
        t.t === 'enlace' ? (
          <a key={i} href={t.v} target="_blank" rel="noopener noreferrer" className={`break-all underline underline-offset-2 ${propio ? 'text-white dark:text-accent-fg' : 'text-[#2f6fed] dark:text-[#7aa7ff]'}`}>
            {t.v}
          </a>
        ) : t.t === 'mencion' ? (
          <span key={i} className={`rounded px-0.5 font-semibold ${propio ? 'bg-white/20' : t.yo ? 'bg-[#fff3c4] text-[#7a5b00] dark:bg-[#3a3214] dark:text-[#f1d27a]' : 'bg-[#e8effc] text-[#2f5fb8] dark:bg-[#1b2333] dark:text-[#a9c1ea]'}`}>
            @{t.v}
          </span>
        ) : (
          <span key={i}>{t.v}</span>
        ),
      )}
    </>
  )
}

export type AccionesMensaje = {
  onReaccionar: (m: Mensaje, emoji: string) => void
  onMenu: (m: Mensaje, e: MouseEvent) => void
  onMenuEn: (m: Mensaje, x: number, y: number) => void
  onCita: (id: number) => void
  onAbrirImagen: (items: Adjunto[], i: number) => void
}

type Props = {
  m: Mensaje
  propio: boolean
  grupo: boolean
  continua: boolean
  leido: boolean
  resaltado: boolean
  nombres: string[]
  yo: string
  acciones: AccionesMensaje
}

/* Una burbuja del chat (.ch-msg): propias a la derecha en negro, ajenas a la
   izquierda con avatar (y nombre en los grupos); cita, adjuntos, hora,
   «editado», tics y reacciones. Al pasar: reaccionar y «Más». */
function BurbujaBase({ m, propio, grupo, continua, leido, resaltado, nombres, yo, acciones }: Props) {
  const [reaccionar, setReaccionar] = useState(false)
  const btnReaccion = useRef<HTMLButtonElement>(null)
  const pulsado = useRef<number | undefined>(undefined)
  const imagenes: Adjunto[] = m.adjuntos.filter((a) => a.imagen).map((a) => ({ id: a.indice, nombre: a.nombre, url: url(a.url), mime: a.mime }))
  const ficheros: Adjunto[] = m.adjuntos.filter((a) => !a.imagen).map((a) => ({ id: a.indice, nombre: a.nombre, url: url(a.url), mime: a.mime, tamano: a.tamano }))
  const reacciones = m.reacciones.map((r) => ({ emoji: r.emoji, n: r.total, mia: r.mio, quienes: r.personas }))

  return (
    <div
      id={`msg-${m.id}`}
      className={`group/msg flex gap-2.5 ${propio ? 'justify-end' : ''} ${continua ? 'mt-0.5' : 'mt-3'}`}
      onContextMenu={(e) => !m.borrado && acciones.onMenu(m, e)}
      // En el móvil no hay clic derecho ni «hover»: mantener pulsado abre el menú.
      onTouchStart={(e) => {
        if (m.borrado) return
        const t = e.touches[0]
        pulsado.current = window.setTimeout(() => acciones.onMenuEn(m, t.clientX, t.clientY), 500)
      }}
      onTouchEnd={() => window.clearTimeout(pulsado.current)}
      onTouchMove={() => window.clearTimeout(pulsado.current)}
    >
      {!propio && (
        <div className="w-[30px] shrink-0">{!continua && <Avatar nombre={m.autor} foto={m.foto} size={30} />}</div>
      )}
      <div className={`flex max-w-[74%] min-w-0 flex-col max-sm:max-w-[84%] ${propio ? 'items-end' : 'items-start'}`}>
        {grupo && !propio && !continua && (
          <span className="mb-1 ml-1 text-[12px] font-bold" style={{ color: colorDe(m.autor) }}>
            {m.autor}
          </span>
        )}
        <div className={`flex items-center gap-1.5 ${propio ? 'flex-row-reverse' : ''}`}>
          <div
            className={`relative min-w-0 px-[15px] py-2.5 text-[13.5px] leading-[1.55] break-words transition-shadow ${
              m.borrado
                ? 'rounded-[14px] border border-dashed border-line bg-transparent text-label italic'
                : propio
                  ? `rounded-[14px] bg-accent text-white dark:text-accent-fg ${continua ? '' : 'rounded-tr-[4px]'}`
                  : `rounded-[14px] border border-line bg-card text-ink ${continua ? '' : 'rounded-tl-[4px]'}`
            } ${resaltado ? 'shadow-[0_0_0_3px_rgba(47,111,237,.45)]' : ''}`}
          >
            {m.borrado ? (
              <span>🚫 Este mensaje fue eliminado</span>
            ) : (
              <>
                {m.responde_a && (
                  <button
                    type="button"
                    onClick={() => acciones.onCita(m.responde_a!.id)}
                    className={`mb-1.5 block w-full rounded-r-md border-l-[3px] border-[#2f6fed] px-2.5 py-1 text-left text-[12px] ${propio ? 'bg-white/15' : 'bg-[rgba(47,111,237,.07)]'}`}
                  >
                    <span className={`block font-bold ${propio ? '' : 'text-[#2f6fed] dark:text-[#7aa7ff]'}`}>{m.responde_a.autor}</span>
                    <span className="line-clamp-2 opacity-80">{m.responde_a.extracto}</span>
                  </button>
                )}
                {imagenes.length > 0 && (
                  <div className="mb-1 flex flex-wrap gap-1.5">
                    {imagenes.map((a, i) => (
                      <button key={a.id} type="button" onClick={() => acciones.onAbrirImagen(imagenes, i)} className="overflow-hidden rounded-[10px]" aria-label={`Ver ${a.nombre}`}>
                        <img src={a.url} alt={a.nombre} loading="lazy" className="max-h-[320px] max-w-[260px] object-cover max-sm:max-w-[200px]" />
                      </button>
                    ))}
                  </div>
                )}
                {ficheros.length > 0 && (
                  <div className="mb-1 flex flex-col gap-1.5 text-ink">
                    {ficheros.map((a) => (
                      <AttachmentChip key={a.id} adjunto={a} />
                    ))}
                  </div>
                )}
                {m.texto && (
                  <p className="whitespace-pre-wrap">
                    <TextoChat texto={m.texto} nombres={nombres} yo={yo} propio={propio} />
                  </p>
                )}
              </>
            )}
            <span className={`mt-0.5 flex items-center justify-end gap-1 text-[10px] ${propio && !m.borrado ? 'text-white/70 dark:text-accent-fg/70' : 'text-label'}`}>
              {m.editado && !m.borrado && <i>editado</i>}
              <span title={m.creado}>{horaMensaje(m.creado)}</span>
              {propio && !m.borrado && (leido ? <CheckCheck className="size-3.5 text-[#53bdeb]" aria-label="Leído" /> : <Check className="size-3.5" aria-label="Enviado" />)}
            </span>
          </div>
          {!m.borrado && (
            <div className="flex shrink-0 items-center gap-0.5 rounded-[9px] border border-line bg-pop p-0.5 opacity-0 shadow-sm transition-opacity group-hover/msg:opacity-100 focus-within:opacity-100 max-sm:hidden">
              <button ref={btnReaccion} type="button" onClick={() => setReaccionar((v) => !v)} aria-label="Reaccionar" title="Reaccionar" className="flex size-[26px] items-center justify-center rounded-[7px] text-label hover:bg-soft hover:text-ink">
                <SmilePlus className="size-[15px]" />
              </button>
              <button type="button" onClick={(e) => acciones.onMenu(m, e)} aria-label="Más" title="Más" className="flex size-[26px] items-center justify-center rounded-[7px] text-label hover:bg-soft hover:text-ink">
                <MoreHorizontal className="size-[15px]" />
              </button>
            </div>
          )}
        </div>
        {reacciones.length > 0 && <ReactionChips reactions={reacciones} onToggle={(emoji) => acciones.onReaccionar(m, emoji)} className={`mt-1 ${propio ? 'justify-end' : ''}`} />}
      </div>
      <Popover open={reaccionar} onClose={() => setReaccionar(false)} anchor={btnReaccion} placement="top-start" unstyled>
        <QuickReactions
          onPick={(emoji) => {
            setReaccionar(false)
            acciones.onReaccionar(m, emoji)
          }}
        />
      </Popover>
    </div>
  )
}

const Burbuja = memo(BurbujaBase)
export default Burbuja
