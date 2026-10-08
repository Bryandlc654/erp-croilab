import { useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { ChevronLeft, ChevronRight, Download, ExternalLink, FileText, Film, Music, Paperclip, X } from 'lucide-react'
import { useCapa, useTrampaFoco } from '../capas'
import { esUrlSegura } from '../../lib/richtext'
import { tamanoLegible, tipoAdjunto, type Adjunto } from '../../lib/adjuntos'
import './rich.css'

export type { Adjunto } from '../../lib/adjuntos'

/* Solo URLs que se pueden abrir sin riesgo (http(s), mailto o rutas propias). */
const segura = (u: string) => esUrlSegura(u) || (u.startsWith('/') && !u.startsWith('//')) || u.startsWith('blob:')
/* Para <img src> vale además una imagen en línea (data:image/…). */
const srcSegura = (u: string) => segura(u) || /^data:image\//.test(u)

const ICONO = { pdf: FileText, video: Film, audio: Music, archivo: Paperclip, imagen: Paperclip }

/* Un adjunto: miniatura 92×92 (imágenes) o chip con nombre (.att-img / .att-file). */
export function AttachmentChip({
  adjunto,
  onOpen,
  onRemove,
  removeLabel = 'Quitar adjunto',
  size = 92,
}: {
  adjunto: Adjunto
  /* Abrir (imágenes y PDF: el Lightbox). Sin él, el chip es un enlace. */
  onOpen?: (a: Adjunto) => void
  onRemove?: (a: Adjunto) => void
  removeLabel?: string
  size?: number
}) {
  const tipo = tipoAdjunto(adjunto.nombre, adjunto.mime)
  const Icono = ICONO[tipo]
  const href = segura(adjunto.url) ? adjunto.url : undefined
  const quitar = onRemove && (
    <button
      type="button"
      aria-label={`${removeLabel}: ${adjunto.nombre}`}
      title={removeLabel}
      onClick={(e) => {
        e.stopPropagation()
        onRemove(adjunto)
      }}
      className="absolute -top-[7px] -right-[7px] z-[1] flex size-5 items-center justify-center rounded-full border border-line bg-card text-label shadow-[0_2px_6px_rgba(0,0,0,.1)] transition-colors hover:text-[#c0392b] dark:hover:text-danger"
    >
      <X className="size-3" strokeWidth={2.4} />
    </button>
  )

  if (tipo === 'imagen') {
    const mini = adjunto.miniatura && srcSegura(adjunto.miniatura) ? adjunto.miniatura : srcSegura(adjunto.url) ? adjunto.url : undefined
    const contenido = <img src={mini} alt={adjunto.nombre} loading="lazy" className="size-full object-cover" />
    const caja = 'block overflow-hidden rounded-[10px] border border-line bg-soft transition-[box-shadow,border-color] hover:border-line-strong hover:shadow-[0_4px_14px_rgba(0,0,0,.08)]'
    return (
      <span className="relative inline-block" style={{ width: size, height: size }}>
        {onOpen ? (
          <button type="button" onClick={() => onOpen(adjunto)} className={`${caja} size-full cursor-zoom-in`} aria-label={`Ver ${adjunto.nombre}`}>
            {contenido}
          </button>
        ) : (
          <a href={href} target="_blank" rel="noopener noreferrer" className={`${caja} size-full`} aria-label={`Abrir ${adjunto.nombre}`}>
            {contenido}
          </a>
        )}
        {quitar}
      </span>
    )
  }

  const cuerpo = (
    <>
      <Icono className="size-4 shrink-0 text-muted" aria-hidden="true" />
      <span className="max-w-[180px] truncate">{adjunto.nombre}</span>
      {adjunto.tamano ? <span className="shrink-0 text-[11.5px] text-label">{tamanoLegible(adjunto.tamano)}</span> : null}
    </>
  )
  const chip = 'inline-flex items-center gap-2 rounded-[10px] border border-line bg-card px-3 py-[9px] text-[12.5px] text-ink transition-colors hover:bg-soft'
  return (
    <span className="relative inline-flex">
      {onOpen && tipo === 'pdf' ? (
        <button type="button" onClick={() => onOpen(adjunto)} className={chip} title={adjunto.nombre}>
          {cuerpo}
        </button>
      ) : (
        <a href={href} target="_blank" rel="noopener noreferrer" className={chip} title={adjunto.nombre}>
          {cuerpo}
        </a>
      )}
      {quitar}
    </span>
  )
}

/* Rejilla de adjuntos con su Lightbox (imágenes y PDF). */
export function AttachmentList({
  items,
  onRemove,
  removeLabel,
  size,
  className = '',
}: {
  items: Adjunto[]
  onRemove?: (a: Adjunto) => void
  removeLabel?: string
  size?: number
  className?: string
}) {
  const visibles = items.filter((a) => ['imagen', 'pdf'].includes(tipoAdjunto(a.nombre, a.mime)))
  const [abierto, setAbierto] = useState<number | null>(null)
  if (!items.length) return null
  return (
    <>
      <div className={`flex flex-wrap items-start gap-2.5 ${className}`}>
        {items.map((a) => (
          <AttachmentChip key={a.id} adjunto={a} size={size} onRemove={onRemove} removeLabel={removeLabel} onOpen={(x) => setAbierto(visibles.findIndex((v) => v.id === x.id))} />
        ))}
      </div>
      <Lightbox items={visibles} index={abierto} onClose={() => setAbierto(null)} onIndexChange={setAbierto} />
    </>
  )
}

/* Visor a pantalla completa (#lightbox del antiguo): imágenes y PDF (en
   <iframe>, sin pdf.js). ←/→ cambian, Esc cierra, clic fuera cierra. */
export function Lightbox({ items, index, onClose, onIndexChange }: { items: Adjunto[]; index: number | null; onClose: () => void; onIndexChange?: (i: number) => void }) {
  if (index === null || index < 0 || !items[index]) return null
  return <Visor items={items} index={index} onClose={onClose} onIndexChange={onIndexChange} />
}

function Visor({ items, index, onClose, onIndexChange }: { items: Adjunto[]; index: number; onClose: () => void; onIndexChange?: (i: number) => void }) {
  const caja = useRef<HTMLDivElement>(null)
  const cerrar = useRef<HTMLButtonElement>(null)
  useCapa(true, { onEscape: onClose, bloquearScroll: true })
  useTrampaFoco(caja, true, cerrar)
  const a = items[index]
  const tipo = tipoAdjunto(a.nombre, a.mime)
  const varios = items.length > 1 && !!onIndexChange
  const ir = (d: number) => onIndexChange?.((index + d + items.length) % items.length)
  const href = segura(a.url) ? a.url : undefined
  const src = srcSegura(a.url) ? a.url : undefined

  return createPortal(
    <div
      ref={caja}
      role="dialog"
      aria-modal="true"
      aria-label={a.nombre}
      className="fixed inset-0 z-[1350] flex cursor-zoom-out flex-col items-center justify-center bg-[rgba(10,12,16,.62)] p-[30px] backdrop-blur-[7px] motion-safe:animate-fade-in max-sm:p-3"
      onClick={onClose}
      onKeyDown={(e) => {
        if (!varios) return
        if (e.key === 'ArrowLeft') ir(-1)
        if (e.key === 'ArrowRight') ir(1)
      }}
    >
      <div className="absolute top-3 right-3 left-3 flex items-center gap-2 text-[13px] text-white/90" onClick={(e) => e.stopPropagation()}>
        <span className="min-w-0 truncate font-semibold">{a.nombre}</span>
        {varios && <span className="shrink-0 text-white/60 tabular-nums">{`${index + 1} / ${items.length}`}</span>}
        <span className="flex-1" />
        {href && (
          <a href={href} target="_blank" rel="noopener noreferrer" download={a.nombre} className="flex size-9 items-center justify-center rounded-[10px] text-white/85 hover:bg-white/15" aria-label="Descargar" title="Descargar">
            <Download className="size-[18px]" />
          </a>
        )}
        {href && tipo === 'pdf' && (
          <a href={href} target="_blank" rel="noopener noreferrer" className="flex size-9 items-center justify-center rounded-[10px] text-white/85 hover:bg-white/15" aria-label="Abrir en otra pestaña" title="Abrir en otra pestaña">
            <ExternalLink className="size-[18px]" />
          </a>
        )}
        <button ref={cerrar} type="button" onClick={onClose} className="flex size-9 items-center justify-center rounded-[10px] text-white/85 hover:bg-white/15" aria-label="Cerrar" title="Cerrar (Esc)">
          <X className="size-5" />
        </button>
      </div>

      {tipo === 'imagen' ? (
        <img
          key={a.id}
          src={src}
          alt={a.nombre}
          onClick={(e) => e.stopPropagation()}
          className="max-h-[92vh] max-w-[92vw] cursor-default rounded-[10px] shadow-[0_24px_70px_rgba(0,0,0,.55)] motion-safe:animate-[lbIn_.24s_cubic-bezier(.2,.8,.3,1)]"
        />
      ) : (
        <div key={a.id} onClick={(e) => e.stopPropagation()} className="flex h-[86vh] w-[900px] max-w-[94vw] cursor-default flex-col overflow-hidden rounded-[14px] bg-white shadow-[0_24px_70px_rgba(0,0,0,.55)]">
          {href ? (
            <iframe src={href} title={a.nombre} className="min-h-0 flex-1 border-0" />
          ) : (
            <p className="m-auto text-[13px] text-[#656a72]">No se puede mostrar este archivo.</p>
          )}
        </div>
      )}

      {varios && (
        <>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation()
              ir(-1)
            }}
            className="absolute top-1/2 left-3 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/35 text-white hover:bg-black/55"
            aria-label="Anterior"
          >
            <ChevronLeft className="size-5" />
          </button>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation()
              ir(1)
            }}
            className="absolute top-1/2 right-3 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/35 text-white hover:bg-black/55"
            aria-label="Siguiente"
          >
            <ChevronRight className="size-5" />
          </button>
        </>
      )}
    </div>,
    document.body,
  )
}
