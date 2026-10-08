import { useEffect, useRef, useState, type DragEvent, type ReactNode, type RefObject } from 'react'
import { Paperclip, SendHorizontal, Smile, X } from 'lucide-react'
import { useToast } from '../useToast'
import RichTextEditor from './RichTextEditor'
import EmojiPicker from './EmojiPicker'
import MentionPopover from './MentionPopover'
import { useMentions } from './useMentions'
import { useEmojiShortcut } from './useEmojiShortcut'
import { BARRA_COMENTARIO, type RichTextEditorHandle } from './richTextTipos'
import type { Comentario, EnvioComentario } from './comentariosTipos'
import { extracto } from '../../lib/richtext'
import { esImagen, validarFicheros } from '../../lib/adjuntos'
import { insertarEnCampo } from '../../lib/campos'
import type { PersonaMencion } from '../../lib/menciones'

export type CommentComposerProps = {
  /* Envía; si falla (lanza), el texto y los adjuntos se conservan. */
  onSend: (envio: EnvioComentario) => Promise<unknown> | void
  /* Comentario al que se responde (barra con la cita) y cómo quitarlo. */
  replyTo?: Comentario | null
  onCancelReply?: () => void
  people?: PersonaMencion[]
  placeholder?: string
  /* 'rich': editor con formato (tareas, actas). 'plain': textarea (chat, notas). */
  variant?: 'rich' | 'plain'
  submitLabel?: string
  /* Adjuntar ficheros (se entregan en `archivos`); `accept` como en <input>. */
  allowFiles?: boolean
  accept?: string
  maxSizeMB?: number
  disabled?: boolean
  autoFocus?: boolean
  /* Algo encima del campo (p. ej. el selector de tipo de nota del CRM). */
  header?: ReactNode
  className?: string
}

type Pendiente = { id: number; file: File; url: string | null }
let siguiente = 0

/* Compositor de comentarios (.cbox del antiguo). Intro envía, Mayús+Intro
   salto de línea; @ menciona; adjuntos al soltar, pegar o con el clip. */
export default function CommentComposer({
  onSend,
  replyTo,
  onCancelReply,
  people,
  placeholder = 'Escribe un comentario…',
  variant = 'rich',
  submitLabel = 'Comentar',
  allowFiles = true,
  accept,
  maxSizeMB = 25,
  disabled = false,
  autoFocus = false,
  header,
  className = '',
}: CommentComposerProps) {
  const { aviso } = useToast()
  const editor = useRef<RichTextEditorHandle>(null)
  const area = useRef<HTMLTextAreaElement>(null)
  const fichero = useRef<HTMLInputElement>(null)
  const botonEmoji = useRef<HTMLButtonElement>(null)
  const [texto, setTexto] = useState('')
  const [pendientes, setPendientes] = useState<Pendiente[]>([])
  const [enviando, setEnviando] = useState(false)
  const [arrastre, setArrastre] = useState(false)
  const [emoji, setEmoji] = useState(false)
  const mn = useMentions({ ref: area, people: people ?? [] })
  useEmojiShortcut(area, () => setEmoji(true), variant === 'plain')

  // Las miniaturas son blob: se liberan al quitar el fichero o desmontar.
  const vivos = useRef(pendientes)
  useEffect(() => {
    vivos.current = pendientes
  })
  useEffect(
    () => () => {
      for (const p of vivos.current) if (p.url) URL.revokeObjectURL(p.url)
    },
    [],
  )

  // Al elegir «Responder», el foco va al campo.
  useEffect(() => {
    if (!replyTo) return
    if (variant === 'plain') area.current?.focus()
    else editor.current?.focus()
  }, [replyTo, variant])

  function anadir(files: File[]) {
    if (!allowFiles) return
    const { validos, rechazados } = validarFicheros(files, { accept, maxSizeMB })
    if (rechazados.length) aviso(rechazados.map((r) => r.motivo).join(' '), { tipo: 'error' })
    setPendientes((ps) => [...ps, ...validos.map((file) => ({ id: ++siguiente, file, url: esImagen({ nombre: file.name, mime: file.type }) ? URL.createObjectURL(file) : null }))])
  }

  function quitar(id: number) {
    setPendientes((ps) => {
      const p = ps.find((x) => x.id === id)
      if (p?.url) URL.revokeObjectURL(p.url)
      return ps.filter((x) => x.id !== id)
    })
  }

  async function enviar() {
    if (enviando || disabled) return
    const cuerpo = (variant === 'plain' ? texto : (editor.current?.getValue() ?? '')).trim()
    if (!cuerpo && pendientes.length === 0) return
    setEnviando(true)
    try {
      await onSend({ cuerpo, archivos: pendientes.map((p) => p.file), replyTo: replyTo?.id ?? null })
      for (const p of pendientes) if (p.url) URL.revokeObjectURL(p.url)
      setPendientes([])
      setTexto('')
      editor.current?.clear()
      onCancelReply?.()
    } catch {
      // El módulo avisa del error; aquí solo no se pierde lo escrito.
    } finally {
      setEnviando(false)
    }
  }

  const botonEnviar =
    variant === 'rich' ? (
      <button
        type="button"
        onClick={() => void enviar()}
        disabled={disabled || enviando}
        className="rounded-lg bg-accent px-3.5 py-[7px] text-[12.5px] font-semibold text-white transition-[transform,box-shadow,opacity] hover:-translate-y-px hover:shadow-[0_5px_13px_rgba(0,0,0,.15)] disabled:opacity-60 dark:text-accent-fg"
      >
        {enviando ? 'Enviando…' : submitLabel}
      </button>
    ) : null

  const chips = pendientes.length > 0 && (
    <div className="mt-1.5 flex flex-wrap gap-1.5">
      {pendientes.map((p) => (
        <span key={p.id} className="inline-flex max-w-[220px] items-center gap-1.5 rounded-lg border border-line bg-soft py-1 pr-1 pl-1 text-[11.5px] text-muted">
          {p.url ? <img src={p.url} alt="" className="size-[34px] rounded-md object-cover" /> : <Paperclip className="ml-1 size-3.5 shrink-0" aria-hidden="true" />}
          <span className="truncate">{p.file.name}</span>
          <button type="button" onClick={() => quitar(p.id)} aria-label={`Quitar ${p.file.name}`} className="rounded p-0.5 text-label hover:text-[#c0392b] dark:hover:text-danger">
            <X className="size-3" />
          </button>
        </span>
      ))}
    </div>
  )

  const respuesta = replyTo && (
    <div className="mb-[9px] flex items-center gap-2.5 rounded-r-lg border-l-[3px] border-accent bg-soft py-[7px] pr-2.5 pl-2.5">
      <div className="flex min-w-0 flex-1 flex-col gap-px">
        <span className="text-[12px] font-bold text-accent">{replyTo.autor?.username ?? '—'}</span>
        <span className="truncate text-[12.5px] text-muted">{extracto(replyTo.cuerpo, 70, { adjuntos: true }) || 'Comentario'}</span>
      </div>
      <button type="button" onClick={onCancelReply} aria-label="Cancelar respuesta" title="Cancelar respuesta" className="shrink-0 rounded-[7px] p-1 text-label hover:bg-[#e6e7ea] hover:text-ink dark:hover:bg-line">
        <X className="size-3.5" />
      </button>
    </div>
  )

  const soltar = {
    onDragOver: (e: DragEvent) => {
      if (!allowFiles || !Array.from(e.dataTransfer.types).includes('Files')) return
      e.preventDefault()
      setArrastre(true)
    },
    onDragLeave: () => setArrastre(false),
    onDrop: (e: DragEvent) => {
      setArrastre(false)
      if (!allowFiles || !e.dataTransfer.files.length) return
      e.preventDefault()
      e.stopPropagation()
      anadir(Array.from(e.dataTransfer.files))
    },
  }

  if (variant === 'plain') {
    return (
      <div className={className} {...soltar}>
        {header}
        {respuesta}
        <div className="flex items-end gap-2">
          {allowFiles && (
            <button
              type="button"
              onClick={() => fichero.current?.click()}
              disabled={disabled}
              title="Adjuntar"
              aria-label="Adjuntar"
              className="flex size-[38px] shrink-0 items-center justify-center rounded-[10px] text-label transition-colors hover:bg-soft hover:text-ink"
            >
              <Paperclip className="size-[18px]" />
            </button>
          )}
          <button
            ref={botonEmoji}
            type="button"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => setEmoji((v) => !v)}
            disabled={disabled}
            title="Emoji (Ctrl+.)"
            aria-label="Emoji"
            className="flex size-[38px] shrink-0 items-center justify-center rounded-[10px] text-label transition-colors hover:bg-soft hover:text-ink"
          >
            <Smile className="size-[18px]" />
          </button>
          <textarea
            ref={area}
            value={texto}
            rows={1}
            disabled={disabled}
            autoFocus={autoFocus}
            placeholder={placeholder}
            aria-label={placeholder}
            onChange={(e) => {
              setTexto(e.target.value)
              // Crece con el texto: de 44 a 120 px.
              e.target.style.height = 'auto'
              e.target.style.height = `${Math.min(120, Math.max(44, e.target.scrollHeight + 2))}px`
            }}
            onPaste={(e) => {
              const files = Array.from(e.clipboardData.files)
              if (files.length && allowFiles) {
                e.preventDefault()
                anadir(files)
              }
            }}
            onKeyDown={(e) => {
              if (mn.onKeyDown(e)) return
              if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
                e.preventDefault()
                void enviar()
              }
            }}
            {...mn.bind}
            className={`max-h-[120px] min-h-[44px] flex-1 resize-none rounded-xl border bg-field px-[15px] py-[11px] text-[13.5px] leading-[1.45] text-ink transition-[border-color,box-shadow] placeholder:text-label focus:border-accent focus:shadow-[0_0_0_3px_var(--c-accent-soft)] focus:outline-none max-sm:text-[16px] ${arrastre ? 'border-accent' : 'border-line'}`}
          />
          <button
            type="button"
            onClick={() => void enviar()}
            disabled={disabled || enviando || (!texto.trim() && pendientes.length === 0)}
            aria-label={submitLabel}
            title={submitLabel}
            className="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-accent text-white transition-[transform,opacity] hover:-translate-y-px disabled:opacity-50 dark:text-accent-fg"
          >
            <SendHorizontal className="size-[18px]" />
          </button>
        </div>
        {chips}
        <MentionPopover {...mn.popover} anchor={area} placement="top-start" />
        <EmojiPicker open={emoji} onClose={() => setEmoji(false)} anchor={botonEmoji} placement="top-start" onPick={(e) => area.current && insertarEnCampo(area.current, e)} />
        <InputFicheros refInput={fichero} accept={accept} onFiles={anadir} />
      </div>
    )
  }

  return (
    <div
      {...soltar}
      className={`rounded-xl border bg-card px-3 py-2.5 transition-[border-color,box-shadow,background-color] duration-150 hover:border-[#d9dade] focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--c-accent-soft)] dark:hover:border-line-strong ${
        arrastre ? 'border-accent shadow-[0_0_0_3px_var(--c-accent-soft)]' : 'border-line'
      } ${className}`}
    >
      {header}
      {respuesta}
      <RichTextEditor
        ref={editor}
        value=""
        variant="compact"
        placeholder={placeholder}
        mentions={people}
        disabled={disabled}
        autoFocus={autoFocus}
        onSubmit={() => void enviar()}
        // Lo que se pega o suelta en el editor también va a los adjuntos pendientes.
        onUploadFiles={
          allowFiles
            ? async (files) => {
                anadir(files)
                return []
              }
            : undefined
        }
        toolbar={allowFiles ? BARRA_COMENTARIO : BARRA_COMENTARIO.filter((h) => h !== 'attach')}
        toolbarEnd={botonEnviar}
        ariaLabel={placeholder}
      />
      {chips}
    </div>
  )
}

function InputFicheros({ refInput, accept, onFiles }: { refInput: RefObject<HTMLInputElement | null>; accept?: string; onFiles: (f: File[]) => void }) {
  return (
    <input
      ref={refInput}
      type="file"
      multiple
      hidden
      accept={accept}
      onChange={(e) => {
        const files = Array.from(e.target.files ?? [])
        e.target.value = ''
        if (files.length) onFiles(files)
      }}
    />
  )
}
