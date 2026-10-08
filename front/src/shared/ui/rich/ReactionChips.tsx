import { useRef, useState } from 'react'
import { Plus, SmilePlus, ThumbsUp } from 'lucide-react'
import EmojiPicker from './EmojiPicker'
import { REACCIONES_RAPIDAS } from '../../lib/emojis'
import './rich.css'

export type Reaccion = {
  emoji: string
  n: number
  /* ¿La he puesto yo? */
  mia: boolean
  /* Nombres para el título (tooltip). */
  quienes?: string[]
}

const FUENTE_EMOJI = { fontFamily: '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif' }

/* Píldoras de reacción (.rchip): emoji + número; la propia resaltada. Con
   `onToggle` se puede quitar/poner; con `addButton`, «+» abre el selector. */
export default function ReactionChips({
  reactions,
  onToggle,
  addButton = false,
  thumb = false,
  className = '',
}: {
  reactions: Reaccion[]
  onToggle?: (emoji: string) => void
  /* Botón para añadir una reacción con el selector de emojis. */
  addButton?: boolean
  /* Pie de comentario del antiguo: 👍 siempre visible delante (con o sin número). */
  thumb?: boolean
  className?: string
}) {
  const [rebote, setRebote] = useState<string | null>(null)
  const [picker, setPicker] = useState(false)
  const mas = useRef<HTMLButtonElement>(null)
  const pulgar = thumb ? reactions.find((r) => r.emoji === '👍') : undefined
  const resto = reactions.filter((r) => r.n > 0 && !(thumb && r.emoji === '👍'))

  function pulsar(emoji: string) {
    if (!onToggle) return
    setRebote(emoji)
    onToggle(emoji)
  }

  return (
    <div className={`flex flex-wrap items-center gap-1.5 ${className}`}>
      {thumb && (
        <button
          type="button"
          disabled={!onToggle}
          onClick={() => pulsar('👍')}
          aria-pressed={!!pulgar?.mia}
          title={pulgar?.quienes?.join(', ') || 'Me gusta'}
          className={`inline-flex items-center gap-[5px] rounded-lg px-2 py-1 text-[12.5px] leading-none font-semibold transition-colors hover:bg-soft disabled:cursor-default disabled:hover:bg-transparent ${
            pulgar?.mia ? 'text-accent' : pulgar?.n ? 'text-[#5a5f68] dark:text-ink' : 'text-label hover:text-[#6b7079]'
          }`}
        >
          {pulgar?.n ? (
            <>
              <span style={FUENTE_EMOJI} className={rebote === '👍' ? 'inline-block motion-safe:animate-[rpop_.34s_cubic-bezier(.2,1.5,.35,1)]' : 'inline-block'} onAnimationEnd={() => setRebote(null)}>
                👍
              </span>
              <span className={`text-[12px] font-bold ${pulgar.mia ? 'text-accent' : 'text-muted'}`}>{pulgar.n}</span>
            </>
          ) : (
            <ThumbsUp className="size-4" aria-label="Me gusta" />
          )}
        </button>
      )}
      {resto.map((r) => (
        <button
          key={r.emoji}
          type="button"
          disabled={!onToggle}
          onClick={() => pulsar(r.emoji)}
          aria-pressed={r.mia}
          aria-label={`${r.emoji} ${r.n}`}
          title={r.quienes?.join(', ')}
          className={`inline-flex items-center gap-1 rounded-full border px-[9px] py-0.5 text-[12.5px] transition-colors disabled:cursor-default ${
            r.mia ? 'border-[#d8d4fb] bg-accent-soft dark:border-line-strong' : 'border-line bg-card hover:bg-soft'
          }`}
        >
          <span style={FUENTE_EMOJI} className={rebote === r.emoji ? 'inline-block motion-safe:animate-[rpop_.34s_cubic-bezier(.2,1.5,.35,1)]' : 'inline-block'} onAnimationEnd={() => setRebote(null)}>
            {r.emoji}
          </span>
          <span className={`text-[11px] font-semibold ${r.mia ? 'text-accent' : 'text-muted'}`}>{r.n}</span>
        </button>
      ))}
      {addButton && onToggle && (
        <>
          <button
            ref={mas}
            type="button"
            onClick={() => setPicker((v) => !v)}
            aria-label="Añadir reacción"
            title="Añadir reacción"
            className="inline-flex size-[26px] items-center justify-center rounded-full text-label transition-colors hover:bg-soft hover:text-ink"
          >
            <SmilePlus className="size-[15px]" />
          </button>
          <EmojiPicker
            open={picker}
            onClose={() => setPicker(false)}
            anchor={mas}
            onPick={(e) => {
              setPicker(false)
              pulsar(e)
            }}
          />
        </>
      )}
    </div>
  )
}

/* Barra de reacciones rápidas (chat): 👍 ❤️ 😂 😮 😢 🙏 🔥 👏 + «+». */
export function QuickReactions({ onPick, className = '' }: { onPick: (emoji: string) => void; className?: string }) {
  const [picker, setPicker] = useState(false)
  const mas = useRef<HTMLButtonElement>(null)
  return (
    <div className={`inline-flex items-center gap-0.5 rounded-full border border-line bg-pop p-1 shadow-pop ${className}`} role="toolbar" aria-label="Reaccionar">
      {REACCIONES_RAPIDAS.map((e) => (
        <button key={e} type="button" onClick={() => onPick(e)} aria-label={`Reaccionar con ${e}`} className="flex size-8 items-center justify-center rounded-full text-[19px] transition-transform hover:scale-125 hover:bg-soft" style={FUENTE_EMOJI}>
          {e}
        </button>
      ))}
      <button ref={mas} type="button" onClick={() => setPicker((v) => !v)} aria-label="Más emojis" className="flex size-8 items-center justify-center rounded-full text-label hover:bg-soft hover:text-ink">
        <Plus className="size-4" />
      </button>
      <EmojiPicker
        open={picker}
        onClose={() => setPicker(false)}
        anchor={mas}
        onPick={(e) => {
          setPicker(false)
          onPick(e)
        }}
      />
    </div>
  )
}
