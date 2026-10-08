import { useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Paperclip, UploadCloud } from 'lucide-react'
import { useToast } from '../useToast'
import { validarFicheros, type Rechazo } from '../../lib/adjuntos'
import { useWindowFileDrop } from './useWindowFileDrop'

export type FileDropzoneProps = {
  onFiles: (files: File[]) => void
  /* Como el `accept` de <input type=file>: «image/*,.pdf». */
  accept?: string
  maxSizeMB?: number
  multiple?: boolean
  label?: ReactNode
  hint?: ReactNode
  disabled?: boolean
  /* Ficheros rechazados (tipo o tamaño). Por defecto, un aviso de error. */
  onReject?: (rechazados: Rechazo<File>[]) => void
  /* Versión baja de una línea (.upl de comentarios). */
  compact?: boolean
  className?: string
}

/* Zona de subida (.upl del antiguo): clic para elegir o soltar encima. */
export default function FileDropzone({
  onFiles,
  accept,
  maxSizeMB = 25,
  multiple = true,
  label = 'Sube imágenes, vídeos o archivos',
  hint = (
    <>
      Haz clic aquí o <b className="font-semibold text-ink">arrastra archivos a cualquier parte</b> de la pantalla
    </>
  ),
  disabled = false,
  onReject,
  compact = false,
  className = '',
}: FileDropzoneProps) {
  const input = useRef<HTMLInputElement>(null)
  const [encima, setEncima] = useState(false)
  const { aviso } = useToast()

  function entregar(lista: File[]) {
    const { validos, rechazados } = validarFicheros(multiple ? lista : lista.slice(0, 1), { accept, maxSizeMB })
    if (rechazados.length) {
      if (onReject) onReject(rechazados)
      else aviso(rechazados.map((r) => r.motivo).join(' '), { tipo: 'error' })
    }
    if (validos.length) onFiles(validos)
  }

  return (
    <div
      role="button"
      tabIndex={disabled ? -1 : 0}
      aria-disabled={disabled || undefined}
      onClick={() => !disabled && input.current?.click()}
      onKeyDown={(e) => {
        if (disabled || (e.key !== 'Enter' && e.key !== ' ')) return
        e.preventDefault()
        input.current?.click()
      }}
      onDragOver={(e) => {
        if (disabled) return
        e.preventDefault()
        setEncima(true)
      }}
      onDragLeave={() => setEncima(false)}
      onDrop={(e) => {
        if (disabled) return
        e.preventDefault()
        // La zona se queda el drop: no lo recoge además la capa de la ventana.
        e.stopPropagation()
        setEncima(false)
        entregar(Array.from(e.dataTransfer.files))
      }}
      className={`group/upl flex cursor-pointer flex-col items-center justify-center text-center text-muted transition-[background-color,border-color,color] duration-150 hover:border-accent hover:bg-soft hover:text-ink aria-disabled:cursor-not-allowed aria-disabled:opacity-60 ${
        compact ? 'gap-1 rounded-[10px] border border-dashed border-[#d4d8de] px-3.5 py-3 text-[12.5px] dark:border-line-strong' : 'gap-[5px] rounded-[14px] border-2 border-dashed border-[#d4d8de] px-5 py-[26px] dark:border-line-strong'
      } ${encima ? '!border-accent bg-soft text-ink' : ''} ${className}`}
    >
      <span className={`text-label transition-colors group-hover/upl:text-accent ${encima ? 'text-accent' : ''}`} aria-hidden="true">
        {compact ? <Paperclip className="size-4" /> : <Paperclip className="size-[22px]" />}
      </span>
      <b className={`font-semibold text-ink ${compact ? 'text-[12.5px]' : 'text-[13.5px]'}`}>{label}</b>
      {hint && !compact && <span className="text-[12px]">{hint}</span>}
      <input
        ref={input}
        type="file"
        hidden
        multiple={multiple}
        accept={accept}
        onChange={(e) => {
          const lista = Array.from(e.target.files ?? [])
          e.target.value = ''
          if (lista.length) entregar(lista)
        }}
      />
    </div>
  )
}

/* Capa a pantalla completa mientras se arrastran ficheros sobre la ventana. */
export function WindowDropOverlay({
  onFiles,
  enabled = true,
  title = 'Suelta para adjuntar',
  hint = 'Los archivos se subirán aquí',
}: {
  onFiles: (files: File[], destino: Element | null) => void
  enabled?: boolean
  title?: ReactNode
  hint?: ReactNode
}) {
  const { activo } = useWindowFileDrop({ onFiles, enabled })
  if (!activo) return null
  return createPortal(
    // Sin eventos de puntero: el drop llega al elemento de debajo (y se sabe dónde cayó).
    <div className="pointer-events-none fixed inset-0 z-[1250] flex items-center justify-center bg-[rgba(123,104,238,.12)] motion-safe:animate-fade-in" aria-live="polite">
      <div className="flex flex-col items-center gap-2 rounded-[20px] border-2 border-dashed border-accent bg-card px-14 py-10 text-center text-accent shadow-[0_30px_80px_rgba(0,0,0,.22)] max-sm:mx-4 max-sm:px-8">
        <UploadCloud className="size-7" aria-hidden="true" />
        <b className="text-[18px] font-semibold text-ink-strong">{title}</b>
        {hint && <span className="text-[13px] text-muted">{hint}</span>}
      </div>
    </div>,
    document.body,
  )
}
