import { useState } from 'react'
import { Plus } from 'lucide-react'

/* Fila «+ Añadir…» al pie de una lista (.ck-add / .inline-add): se escribe y
   Enter (o «Añadir») crea. Con el título vacío, `onEmptySubmit` (p. ej. abrir
   el modal de creación completo). */
export default function InlineAddRow({
  placeholder = 'Añadir…',
  onAdd,
  buttonLabel = 'Añadir',
  onEmptySubmit,
  disabled = false,
  className = '',
}: {
  placeholder?: string
  onAdd: (texto: string) => void | Promise<unknown>
  buttonLabel?: string
  onEmptySubmit?: () => void
  disabled?: boolean
  className?: string
}) {
  const [texto, setTexto] = useState('')
  const [enviando, setEnviando] = useState(false)

  async function enviar() {
    const t = texto.trim()
    if (!t) {
      onEmptySubmit?.()
      return
    }
    setEnviando(true)
    try {
      await onAdd(t)
      setTexto('')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <form
      className={`group/add flex items-center gap-2.5 px-[18px] py-[11px] text-label transition-colors focus-within:bg-hover-row hover:bg-hover-row ${className}`}
      onSubmit={(e) => {
        e.preventDefault()
        void enviar()
      }}
    >
      <Plus className="size-4 shrink-0 transition-transform duration-[180ms] group-focus-within/add:rotate-90 group-hover/add:rotate-90" aria-hidden="true" />
      <input
        type="text"
        value={texto}
        disabled={disabled || enviando}
        onChange={(e) => setTexto(e.target.value)}
        onKeyDown={(e) => e.key === 'Escape' && setTexto('')}
        placeholder={placeholder}
        aria-label={placeholder}
        className="min-w-0 flex-1 bg-transparent text-[13.5px] text-ink placeholder:text-label focus:outline-none max-sm:text-[16px]"
      />
      {(texto.trim() || onEmptySubmit) && (
        <button
          type="submit"
          disabled={disabled || enviando}
          className="shrink-0 rounded-lg bg-accent px-[13px] py-1.5 text-[12.5px] font-semibold text-white transition-[transform,box-shadow] hover:-translate-y-px hover:shadow-[0_5px_13px_rgba(0,0,0,.15)] disabled:opacity-60 dark:text-accent-fg"
        >
          {enviando ? 'Añadiendo…' : buttonLabel}
        </button>
      )}
    </form>
  )
}
