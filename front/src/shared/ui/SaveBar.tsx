import type { ReactNode } from 'react'
import Button from './Button'
import { useRecienGuardado } from '../lib/useUnsavedGuard'

/* «Guardado ✓» (1,3 s tras cada guardado, `savedAt` cambia) o «Sin guardar»
   con su punto ámbar mientras hay cambios (.saved-note, .aj-flag). */
export function SavedIndicator({ dirty = false, savedAt, className = '' }: { dirty?: boolean; savedAt?: number | string | null; className?: string }) {
  const reciente = useRecienGuardado(savedAt ?? null)
  if (dirty)
    return (
      <span role="status" className={`inline-flex items-center gap-1.5 text-[12px] font-semibold text-[#b7791f] motion-safe:animate-fade-in dark:text-warn ${className}`}>
        <span className="size-[7px] rounded-full bg-[#e8a33d]" aria-hidden="true" /> Sin guardar
      </span>
    )
  return (
    <span role="status" className={`text-[12px] font-semibold text-ok transition-opacity duration-200 ${reciente ? 'opacity-100' : 'opacity-0'} ${className}`}>
      {reciente ? 'Guardado ✓' : ''}
    </span>
  )
}

/* Barra de guardar pegada abajo (.ed-guardar): translúcida con desenfoque,
   ocupa el ancho del contenido (sale del padding de la página). */
export function StickySaveBar({
  dirty,
  saving = false,
  onSave,
  onCancel,
  note,
  savedAt,
  saveLabel = 'Guardar cambios',
  bleed = true,
  className = '',
}: {
  dirty: boolean
  saving?: boolean
  onSave: () => void
  onCancel?: () => void
  note?: ReactNode
  savedAt?: number | string | null
  saveLabel?: string
  /* Sale del padding de la página para ocupar todo el ancho (como .ed-guardar). */
  bleed?: boolean
  className?: string
}) {
  return (
    <div
      className={`sticky bottom-0 z-20 flex flex-wrap items-center gap-2.5 border-t border-line bg-page/90 py-3.5 backdrop-blur-[6px] ${
        bleed ? '-mx-4 px-4 md:-mx-6 md:px-6 lg:-mx-[52px] lg:px-[52px]' : 'px-4'
      } ${className}`}
    >
      <Button onClick={onSave} disabled={!dirty} loading={saving} loadingText="Guardando…">
        {saveLabel}
      </Button>
      {onCancel && (
        <Button variant="ghost" onClick={onCancel} disabled={!dirty || saving}>
          Cancelar
        </Button>
      )}
      <SavedIndicator dirty={dirty && !saving} savedAt={savedAt} />
      {note && <span className="ml-auto text-[12.5px] text-muted">{note}</span>}
    </div>
  )
}
