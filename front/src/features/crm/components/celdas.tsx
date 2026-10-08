import { useState, type InputHTMLAttributes } from 'react'
import { importeCelda } from '../logica'

/* Celdas editables en línea (td.ed del CRM): sin borde, fondo suave al pasar
   y blanco al editar. Guardan al salir del campo o con Intro si el valor ha
   cambiado (el «change» del antiguo); Esc deshace lo tecleado. */

const CELDA =
  'w-full min-w-[90px] rounded-[7px] border border-transparent bg-transparent px-2 py-1.5 text-[13px] text-ink placeholder:text-label/70 transition-[background-color,box-shadow] hover:bg-soft focus:bg-card focus:shadow-[0_0_0_2px_rgba(17,19,24,.08)] focus:outline-none read-only:hover:bg-transparent dark:focus:shadow-[0_0_0_2px_rgba(255,255,255,.12)] max-sm:text-[16px]'

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange' | 'defaultValue'> & {
  value: string
  onSave: (v: string) => void
  readOnly?: boolean
}

export function CeldaTexto({ value, onSave, readOnly, className = '', title, ...resto }: Props) {
  const [texto, setTexto] = useState(value)
  const [previo, setPrevio] = useState(value)
  // Si el valor cambia desde fuera (respuesta del servidor, reversión), se refleja.
  if (value !== previo) {
    setPrevio(value)
    setTexto(value)
  }
  return (
    <input
      {...resto}
      value={texto}
      readOnly={readOnly}
      title={title ?? (texto.length > 24 ? texto : undefined)}
      onChange={(e) => setTexto(e.target.value)}
      onClick={(e) => e.stopPropagation()}
      onBlur={() => {
        if (texto.trim() !== value.trim()) onSave(texto.trim())
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter') e.currentTarget.blur()
        if (e.key === 'Escape') {
          e.stopPropagation()
          const el = e.currentTarget
          setTexto(value)
          requestAnimationFrame(() => el.blur())
        }
      }}
      className={`${CELDA} max-w-[200px] truncate ${className}`}
    />
  )
}

/** Importe: se ve «12.500» y se escribe como se quiera («12.500», «12500,5»). */
export function CeldaImporte({ value, onSave, readOnly, className = '', ...resto }: Omit<Props, 'value'> & { value: number | null }) {
  const visible = importeCelda(value)
  return (
    <CeldaTexto
      {...resto}
      value={visible}
      readOnly={readOnly}
      inputMode="decimal"
      onSave={onSave}
      className={`!w-[88px] !min-w-[88px] text-right tabular-nums ${className}`}
    />
  )
}
