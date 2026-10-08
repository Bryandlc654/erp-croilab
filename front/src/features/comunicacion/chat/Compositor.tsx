import { useEffect, useRef, useState, type DragEvent } from 'react'
import { Mic, Paperclip, Pencil, SendHorizontal, Smile, X } from 'lucide-react'
import { insertarEnCampo } from '../../../shared/lib/campos'
import { esImagen, validarFicheros } from '../../../shared/lib/adjuntos'
import type { Persona } from '../../../shared/schemas'
import { EmojiPicker, MentionPopover, useEmojiShortcut, useMentions } from '../../../shared/ui/rich'
import { useToast } from '../../../shared/ui/useToast'
import { ACEPTA_CHAT } from '../logica'
import type { Mensaje } from '../schemas'

type Pendiente = { id: number; file: File; url: string | null }
let siguiente = 0

/* Reconocimiento de voz del navegador (Chrome lo tiene con prefijo). */
type ResultadoVoz = { isFinal: boolean; 0: { transcript: string } }
type Reconocedor = {
  lang: string
  continuous: boolean
  interimResults: boolean
  onresult: ((e: { resultIndex: number; results: ArrayLike<ResultadoVoz> }) => void) | null
  onerror: ((e: { error: string }) => void) | null
  onend: (() => void) | null
  start: () => void
  stop: () => void
}
function crearReconocedor(): Reconocedor | null {
  const w = window as unknown as { SpeechRecognition?: new () => Reconocedor; webkitSpeechRecognition?: new () => Reconocedor }
  const C = w.SpeechRecognition ?? w.webkitSpeechRecognition
  return C ? new C() : null
}

/* Compositor del chat (.ch-compose): emoji, adjuntar, dictado, texto que crece
   hasta 120 px, enviar. Intro envía y Mayús+Intro hace salto; pegar imágenes
   las adjunta; @ menciona; ↑ con el campo vacío edita tu último mensaje;
   Esc cancela la respuesta o la edición. Avisa de «escribiendo» cada 2,5 s. */
export default function Compositor({
  personas,
  respondiendo,
  editando,
  onCancelar,
  onEnviar,
  onGuardarEdicion,
  onEscribiendo,
  onEditarUltimo,
}: {
  personas: Persona[]
  respondiendo: Mensaje | null
  editando: Mensaje | null
  onCancelar: () => void
  onEnviar: (texto: string, archivos: File[]) => Promise<unknown>
  onGuardarEdicion: (m: Mensaje, texto: string) => Promise<unknown>
  onEscribiendo: () => void
  onEditarUltimo: () => void
}) {
  const { aviso } = useToast()
  const area = useRef<HTMLTextAreaElement>(null)
  const fichero = useRef<HTMLInputElement>(null)
  const botonEmoji = useRef<HTMLButtonElement>(null)
  const [texto, setTexto] = useState('')
  const [pendientes, setPendientes] = useState<Pendiente[]>([])
  const [enviando, setEnviando] = useState(false)
  const [emoji, setEmoji] = useState(false)
  const [arrastre, setArrastre] = useState(false)
  const [dictando, setDictando] = useState(false)
  const voz = useRef<Reconocedor | null>(null)
  const ultimoAviso = useRef(0)
  const [borrador, setBorrador] = useState('')
  const mn = useMentions({ ref: area, people: personas })
  useEmojiShortcut(area, () => setEmoji(true))

  // Al empezar a editar se carga el texto del mensaje (y se guarda lo que se estaba
  // escribiendo para devolverlo al acabar). Se ajusta al renderizar, sin efecto.
  const editandoId = editando?.id ?? 0
  const [editandoPrevio, setEditandoPrevio] = useState(0)
  if (editandoId !== editandoPrevio) {
    setEditandoPrevio(editandoId)
    if (editando) {
      setBorrador(texto)
      setTexto(editando.texto)
    }
  }
  useEffect(() => {
    if (!editandoId) return
    requestAnimationFrame(() => {
      const el = area.current
      if (!el) return
      el.focus()
      el.setSelectionRange(el.value.length, el.value.length)
      ajustar(el)
    })
  }, [editandoId])

  useEffect(() => {
    if (respondiendo) area.current?.focus()
  }, [respondiendo])

  // Miniaturas blob y dictado: se liberan al desmontar.
  const vivos = useRef(pendientes)
  useEffect(() => {
    vivos.current = pendientes
  })
  useEffect(
    () => () => {
      for (const p of vivos.current) if (p.url) URL.revokeObjectURL(p.url)
      voz.current?.stop()
    },
    [],
  )

  function ajustar(el: HTMLTextAreaElement) {
    el.style.height = 'auto'
    el.style.height = `${Math.min(120, Math.max(44, el.scrollHeight + 2))}px`
  }

  function anadir(files: File[]) {
    const { validos, rechazados } = validarFicheros(files, { accept: ACEPTA_CHAT, maxSizeMB: 25 })
    if (rechazados.length) aviso(rechazados.map((r) => r.motivo).join(' '), { tipo: 'error' })
    const sitio = 10 - pendientes.length
    if (validos.length > sitio) aviso('Como mucho 10 adjuntos por mensaje.', { tipo: 'error' })
    setPendientes((ps) => [...ps, ...validos.slice(0, Math.max(0, sitio)).map((file) => ({ id: ++siguiente, file, url: esImagen({ nombre: file.name, mime: file.type }) ? URL.createObjectURL(file) : null }))])
  }

  function quitar(id: number) {
    setPendientes((ps) => {
      const p = ps.find((x) => x.id === id)
      if (p?.url) URL.revokeObjectURL(p.url)
      return ps.filter((x) => x.id !== id)
    })
  }

  function cancelar() {
    if (editando) setTexto(borrador)
    onCancelar()
  }

  async function enviar() {
    if (enviando) return
    const t = texto.trim()
    if (editando) {
      if (!t && !editando.adjuntos.length) return
      setEnviando(true)
      try {
        if (t !== editando.texto) await onGuardarEdicion(editando, t)
        setTexto(borrador)
        onCancelar()
      } catch {
        // El error ya se ha avisado; lo escrito se conserva.
      } finally {
        setEnviando(false)
      }
      return
    }
    if (!t && !pendientes.length) return
    setEnviando(true)
    try {
      await onEnviar(t, pendientes.map((p) => p.file))
      for (const p of pendientes) if (p.url) URL.revokeObjectURL(p.url)
      setPendientes([])
      setTexto('')
      if (area.current) area.current.style.height = '44px'
      onCancelar()
    } catch {
      // Se conserva lo escrito y los adjuntos para reintentar.
    } finally {
      setEnviando(false)
      area.current?.focus()
    }
  }

  function dictar() {
    if (dictando) {
      voz.current?.stop()
      return
    }
    const r = crearReconocedor()
    if (!r) {
      aviso('Tu navegador no permite el dictado por voz (prueba con Chrome)', { tipo: 'error' })
      return
    }
    const base = texto ? texto.replace(/\s*$/, ' ') : ''
    r.lang = 'es-ES'
    r.continuous = true
    r.interimResults = true
    r.onresult = (e) => {
      let dicho = ''
      for (let i = 0; i < e.results.length; i++) dicho += e.results[i][0].transcript
      setTexto(base + dicho)
    }
    r.onerror = (e) => {
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') aviso('Da permiso al micrófono para dictar', { tipo: 'error' })
    }
    r.onend = () => setDictando(false)
    voz.current = r
    r.start()
    setDictando(true)
  }

  const soltar = {
    onDragOver: (e: DragEvent) => {
      if (!Array.from(e.dataTransfer.types).includes('Files') || editando) return
      e.preventDefault()
      setArrastre(true)
    },
    onDragLeave: () => setArrastre(false),
    onDrop: (e: DragEvent) => {
      setArrastre(false)
      if (!e.dataTransfer.files.length || editando) return
      e.preventDefault()
      anadir(Array.from(e.dataTransfer.files))
    },
  }

  const barra = editando ?? respondiendo
  const herramienta = 'flex size-[38px] shrink-0 items-center justify-center rounded-[10px] text-label transition-colors hover:bg-soft hover:text-ink disabled:opacity-40'
  return (
    <div className="border-t border-line bg-card px-4 pt-3 pb-3.5 max-sm:px-2.5 max-sm:pb-2.5" {...soltar}>
      {barra && (
        <div className="mb-2.5 flex items-center gap-2.5 rounded-r-lg border-l-[3px] border-[#2f6fed] bg-[#f6f7f9] py-[7px] pr-2.5 pl-2.5 dark:bg-soft">
          {editando && <Pencil className="size-4 shrink-0 text-[#2f6fed]" aria-hidden="true" />}
          <div className="flex min-w-0 flex-1 flex-col gap-px">
            <span className="text-[12px] font-bold text-[#2f6fed] dark:text-[#7aa7ff]">{editando ? 'Editando mensaje' : barra.autor}</span>
            <span className="truncate text-[12.5px] text-muted">{barra.texto || (barra.adjuntos.length ? '📎 Adjunto' : '')}</span>
          </div>
          <button type="button" onClick={cancelar} aria-label={editando ? 'Cancelar la edición' : 'Cancelar la respuesta'} className="shrink-0 rounded-[7px] p-1 text-label hover:bg-[#e6e7ea] hover:text-ink dark:hover:bg-line">
            <X className="size-3.5" />
          </button>
        </div>
      )}
      {pendientes.length > 0 && (
        <div className="mb-2.5 flex flex-wrap gap-1.5">
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
      )}
      <div className="flex items-end gap-1.5">
        <button ref={botonEmoji} type="button" onMouseDown={(e) => e.preventDefault()} onClick={() => setEmoji((v) => !v)} title="Emoji (Ctrl+.)" aria-label="Emoji" className={herramienta}>
          <Smile className="size-[19px]" />
        </button>
        <button type="button" onClick={() => fichero.current?.click()} disabled={!!editando} title="Adjuntar" aria-label="Adjuntar" className={herramienta}>
          <Paperclip className="size-[19px]" />
        </button>
        <button
          type="button"
          onClick={dictar}
          title={dictando ? 'Parar el dictado' : 'Dictar por voz'}
          aria-label={dictando ? 'Parar el dictado' : 'Dictar por voz'}
          aria-pressed={dictando}
          className={`${herramienta} max-sm:hidden ${dictando ? '!bg-[#fdecec] !text-[#ef4444] motion-safe:animate-pulse' : ''}`}
        >
          <Mic className="size-[19px]" />
        </button>
        <textarea
          ref={area}
          value={texto}
          rows={1}
          placeholder="Escribe un mensaje…"
          aria-label="Escribe un mensaje"
          onChange={(e) => {
            setTexto(e.target.value)
            ajustar(e.target)
            const ahora = Date.now()
            if (e.target.value.trim() && !editando && ahora - ultimoAviso.current > 2500) {
              ultimoAviso.current = ahora
              onEscribiendo()
            }
          }}
          onPaste={(e) => {
            const files = Array.from(e.clipboardData.files)
            if (files.length && !editando) {
              e.preventDefault()
              anadir(files)
            }
          }}
          onKeyDown={(e) => {
            if (mn.onKeyDown(e)) return
            if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
              e.preventDefault()
              void enviar()
            } else if (e.key === 'Escape' && barra) {
              e.preventDefault()
              cancelar()
            } else if (e.key === 'ArrowUp' && !texto && !editando) {
              e.preventDefault()
              onEditarUltimo()
            }
          }}
          {...mn.bind}
          className={`max-h-[120px] min-h-[44px] flex-1 resize-none rounded-xl border bg-field px-[15px] py-[11px] text-[13.5px] leading-[1.45] text-ink transition-[border-color,box-shadow] placeholder:text-label focus:border-accent focus:shadow-[0_0_0_3px_var(--c-accent-soft)] focus:outline-none max-sm:text-[16px] ${arrastre ? 'border-accent' : 'border-line'}`}
        />
        <button
          type="button"
          onClick={() => void enviar()}
          disabled={enviando || (!texto.trim() && (editando ? !editando.adjuntos.length : pendientes.length === 0))}
          aria-label={editando ? 'Guardar' : 'Enviar'}
          title={editando ? 'Guardar' : 'Enviar'}
          className="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-accent text-white transition-[transform,opacity] hover:-translate-y-px disabled:opacity-50 dark:text-accent-fg"
        >
          <SendHorizontal className="size-[18px]" />
        </button>
      </div>
      <MentionPopover {...mn.popover} anchor={area} placement="top-start" />
      <EmojiPicker open={emoji} onClose={() => setEmoji(false)} anchor={botonEmoji} placement="top-start" onPick={(e) => area.current && insertarEnCampo(area.current, e)} />
      <input
        ref={fichero}
        type="file"
        multiple
        accept={ACEPTA_CHAT}
        className="hidden"
        onChange={(e) => {
          anadir(Array.from(e.target.files ?? []))
          e.target.value = ''
        }}
      />
    </div>
  )
}
